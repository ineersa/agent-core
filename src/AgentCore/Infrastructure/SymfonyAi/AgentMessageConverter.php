<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolResultType;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

/**
 * Converts AgentCore domain AgentMessages into Symfony AI MessageBag
 * for provider request construction.
 *
 * Owns one worker-local active MessageBag and the target model it was built
 * for. Retained same-target contexts reuse that bag. Appends convert only new
 * AgentMessages. Target switches and replaced prefixes rebuild once. Generic
 * {@see toMessageBag()} callers always get a fresh bag and do not touch the
 * retained active bag. Retention is limited to 16 MiB of estimated payload;
 * oversized contexts still convert fully. Worker idle events release the bag
 * after 60 seconds without cache use, without invalidating returned messages.
 *
 * Supports image_ref content parts: when a tool AgentMessage contains
 * an image_ref content part, the converter emits a synthetic UserMessage
 * containing a real Symfony AI Image attachment after the normal tool
 * call message. This provides the Pi-style fallback for multimodal tool
 * results where tool output must include multimodal image content.
 * ToolCallMessage holds ContentInterface parts (Symfony AI 0.11), but
 * provider normalizers for tool-role messages still serialize text output;
 * a follow-up UserMessage with Image is the supported multimodal path.
 *
 * Image capability gating is handled upstream by ImageGatingConvertHook
 * (a ConvertToLlmHookInterface implementation). This converter always
 * attaches images when image_ref content parts are present — it trusts
 * that upstream hooks have already stripped image_ref from messages for
 * non-vision models before calling toMessageBag().
 *
 * Intentionally does not implement ResetInterface so Messenger service
 * resets preserve incremental reuse across ExecuteLlmStep messages. Size and
 * idle eviction, rather than per-message resets, bound this disposable cache.
 */
final class AgentMessageConverter
{
    private const int RETAINED_PAYLOAD_LIMIT = 16 * 1024 * 1024;

    private ?\DateTimeImmutable $lastUsedAt = null;

    private ?MessageBag $activeBag = null;

    private ?string $activeTargetModel = null;

    /** @var list<AgentMessage> */
    private array $activeSourceMessages = [];

    /** @var array<string, string> */
    private array $idMap = [];

    /** @var array<string, true> */
    private array $usedIds = [];

    /** @var list<array<string, bool>> */
    private array $imageReadability = [];

    public function __construct(
        private readonly ConversationHistoryConversion $historyConversion = new ConversationHistoryConversion(),
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * Convert a list of AgentMessages into a fresh Symfony AI MessageBag.
     *
     * Does not read or write the worker-local active bag.
     *
     * Image capability gating is handled upstream by
     * ImageGatingConvertHook (a ConvertToLlmHookInterface implementation).
     * This converter always attaches images when image_ref content parts
     * are present — it trusts that upstream hooks have already stripped
     * them for non-vision models.
     *
     * @param list<AgentMessage> $agentMessages
     */
    public function toMessageBag(array $agentMessages): MessageBag
    {
        return new MessageBag(...$this->convertAgentMessages($agentMessages));
    }

    /**
     * Build or reuse the active MessageBag for a resolved target model.
     *
     * Guard order:
     * 1. empty target → fresh bag, no active-bag update
     * 2. no active bag / different target / changed prefix / changed image readability → rebuild
     * 3. exact same context → return active bag
     * 4. otherwise append only the new suffix
     *
     * When the previous active source ended mid consecutive tool-result batch,
     * append rebuilds only that trailing open batch plus the suffix so deferred
     * synthetic image UserMessages keep correct ordering. Closed turns skip that
     * path entirely. Ordinary appends keep bag identity via MessageBag::add(). Open trailing
     * tool-image batches may rebuild the deferred tail. Current request
     * shapers rebuild messages rather than mutating the supplied bag.
     *
     * @param list<AgentMessage> $agentMessages
     */
    public function toMessageBagForTarget(array $agentMessages, string $targetModel): MessageBag
    {
        if ('' === $targetModel) {
            return $this->toMessageBag($agentMessages);
        }

        $this->lastUsedAt = $this->clock->now();

        if (null === $this->activeBag
            || $this->activeTargetModel !== $targetModel
            || !$this->isExactPrefix($this->activeSourceMessages, $agentMessages)
            || !$this->imageReadabilityMatchesPrefix($agentMessages)
        ) {
            $this->rebuildActiveBag($agentMessages, $targetModel);

            return $this->releaseOversizedBag($agentMessages);
        }

        if (\count($this->activeSourceMessages) === \count($agentMessages)) {
            return $this->activeBag;
        }

        $this->appendToActiveBag($agentMessages, $targetModel);

        return $this->releaseOversizedBag($agentMessages);
    }

    public function evictIdleCache(): void
    {
        if (null !== $this->lastUsedAt && $this->clock->now() >= $this->lastUsedAt->modify('+60 seconds')) {
            $this->clearActiveBag();
        }
    }

    /**
     * Convert AgentMessages into Symfony messages while preserving tool-batch
     * synthetic image ordering.
     *
     * @param list<AgentMessage> $agentMessages
     *
     * @return list<MessageInterface>
     */
    public function convertAgentMessages(array $agentMessages): array
    {
        $messages = [];
        $pendingSyntheticToolMessages = [];

        foreach ($agentMessages as $agentMessage) {
            if ('tool' !== $agentMessage->role && [] !== $pendingSyntheticToolMessages) {
                array_push($messages, ...$pendingSyntheticToolMessages);
                $pendingSyntheticToolMessages = [];
            }

            $convertedMessages = $this->convertAgentMessage($agentMessage);

            if ('tool' === $agentMessage->role) {
                $primaryMessage = array_shift($convertedMessages);
                if (null !== $primaryMessage) {
                    $messages[] = $primaryMessage;
                }

                array_push($pendingSyntheticToolMessages, ...$convertedMessages);

                continue;
            }

            array_push($messages, ...$convertedMessages);
        }

        if ([] !== $pendingSyntheticToolMessages) {
            array_push($messages, ...$pendingSyntheticToolMessages);
        }

        return $messages;
    }

    /** @param list<AgentMessage> $messages */
    private function releaseOversizedBag(array $messages): MessageBag
    {
        \assert(null !== $this->activeBag);
        $bag = $this->activeBag;
        $remaining = self::RETAINED_PAYLOAD_LIMIT;
        $nodes = 8192;
        foreach ($messages as $message) {
            if (!$this->consumePayloadBudget($message->toArray(), $remaining, $nodes)) {
                $this->clearActiveBag();

                return $bag;
            }
        }
        // Conversion can allocate joined text or expanded JSON. Count both graphs,
        // conservatively counting shared strings twice rather than missing copies.
        foreach ($bag as $message) {
            $content = $message->getContent();
            $parts = \is_array($content) ? $content : [$content];
            if ($message instanceof ToolCallMessage) {
                $parts[] = $message->getToolCall();
            }
            foreach ($parts as $part) {
                $payload = match (true) {
                    $part instanceof Text => [$part->getText(), $part->getSignature()],
                    $part instanceof Thinking => [$part->getContent(), $part->getSignature()],
                    $part instanceof ToolCall => [$part->getId(), $part->getName(), $part->getArguments(), $part->getSignature()],
                    // This converter creates file-backed images only. Count their
                    // retained path/format without loading or encoding file bytes.
                    $part instanceof Image && null !== $part->asPath() => [$part->asPath(), $part->getFormat()],
                    default => $part,
                };
                if (!$this->consumePayloadBudget($payload, $remaining, $nodes)) {
                    $this->clearActiveBag();

                    return $bag;
                }
            }
        }

        return $bag;
    }

    /**
     * Conservative payload estimate, not PHP heap accounting: shared strings count
     * repeatedly and node allowances approximate containers. Never serialize the
     * full context. Unknown objects, deep/cyclic arrays and excessive node counts
     * disable retention rather than inspecting arbitrary graphs without a bound.
     */
    private function consumePayloadBudget(mixed $value, int &$remaining, int &$nodes, int $depth = 0): bool
    {
        if (0 >= --$nodes || 16 < $depth || (null !== $value && !\is_scalar($value) && !\is_array($value))) {
            return false;
        }
        $remaining -= \is_string($value) ? \strlen($value) + 32 : 64;
        if (0 > $remaining) {
            return false;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $remaining -= \is_string($key) ? \strlen($key) + 32 : 32;
                if (!$this->consumePayloadBudget($item, $remaining, $nodes, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function clearActiveBag(): void
    {
        $this->activeBag = null;
        $this->activeTargetModel = null;
        $this->activeSourceMessages = [];
        $this->idMap = [];
        $this->usedIds = [];
        $this->imageReadability = [];
        $this->lastUsedAt = null;
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function rebuildActiveBag(array $agentMessages, string $targetModel): void
    {
        $idMap = [];
        $usedIds = [];
        $converted = $this->historyConversion->convertMessages($agentMessages, $targetModel, $idMap, $usedIds);

        $this->activeBag = new MessageBag(...$this->convertAgentMessages($converted));
        $this->activeTargetModel = $targetModel;
        $this->activeSourceMessages = $agentMessages;
        $this->idMap = $idMap;
        $this->usedIds = $usedIds;
        $this->imageReadability = array_map(
            $this->imageReadabilityForMessage(...),
            $agentMessages,
        );
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function appendToActiveBag(array $agentMessages, string $targetModel): void
    {
        \assert(null !== $this->activeBag);

        $previousCount = \count($this->activeSourceMessages);
        $suffixSource = \array_slice($agentMessages, $previousCount);
        $openBatchStart = $this->trailingOpenToolBatchStart($this->activeSourceMessages);

        // Fallible work first: convert + prepare Symfony messages, then mutate
        // active state only with bag->add / assignment of already-built values.
        $idMap = $this->idMap;
        $usedIds = $this->usedIds;
        $imageReadability = array_map(
            $this->imageReadabilityForMessage(...),
            $agentMessages,
        );

        if (null === $openBatchStart) {
            $suffixConverted = $this->historyConversion->convertMessages(
                $suffixSource,
                $targetModel,
                $idMap,
                $usedIds,
            );
            $newMessages = $this->convertAgentMessages($suffixConverted);

            foreach ($newMessages as $message) {
                $this->activeBag->add($message);
            }

            $this->idMap = $idMap;
            $this->usedIds = $usedIds;
            $this->activeSourceMessages = $agentMessages;
            $this->imageReadability = $imageReadability;
            $this->activeTargetModel = $targetModel;

            return;
        }

        // Continuing an open trailing tool-image batch requires rebuilding the
        // deferred synthetic image region. Keep the stable prefix instances.
        $openBatchToolOutputs = \array_slice($this->activeSourceMessages, $openBatchStart);
        $stableBagCount = \count($this->activeBag->getMessages())
            - \count($this->convertAgentMessages($openBatchToolOutputs));
        $rebuildConverted = $this->historyConversion->convertMessages(
            array_merge($openBatchToolOutputs, $suffixSource),
            $targetModel,
            $idMap,
            $usedIds,
        );
        $rebuiltTail = $this->convertAgentMessages($rebuildConverted);
        $bag = new MessageBag(...\array_slice($this->activeBag->getMessages(), 0, $stableBagCount));
        foreach ($rebuiltTail as $message) {
            $bag->add($message);
        }

        $this->activeBag = $bag;
        $this->idMap = $idMap;
        $this->usedIds = $usedIds;
        $this->activeSourceMessages = $agentMessages;
        $this->imageReadability = $imageReadability;
        $this->activeTargetModel = $targetModel;
    }

    /**
     * When the active source ends inside a consecutive tool-result batch,
     * return that batch's start index. Synthetic image UserMessages are
     * deferred until the tool batch closes, so later tool results must rebuild
     * only this trailing region.
     *
     * @param list<AgentMessage> $messages
     */
    private function trailingOpenToolBatchStart(array $messages): ?int
    {
        $count = \count($messages);
        if (0 === $count || 'tool' !== $messages[$count - 1]->role) {
            return null;
        }

        $start = $count - 1;
        while ($start > 0 && 'tool' === $messages[$start - 1]->role) {
            --$start;
        }

        return $start;
    }

    /**
     * @param list<AgentMessage> $prefix
     * @param list<AgentMessage> $messages
     */
    private function isExactPrefix(array $prefix, array $messages): bool
    {
        $prefixCount = \count($prefix);
        if ($prefixCount > \count($messages)) {
            return false;
        }

        for ($i = 0; $i < $prefixCount; ++$i) {
            if (!$this->messagesEqual($prefix[$i], $messages[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function imageReadabilityMatchesPrefix(array $agentMessages): bool
    {
        $prefixCount = \count($this->imageReadability);
        for ($i = 0; $i < $prefixCount; ++$i) {
            if ($this->imageReadability[$i] !== $this->imageReadabilityForMessage($agentMessages[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, bool>
     */
    private function imageReadabilityForMessage(AgentMessage $message): array
    {
        $readability = [];
        foreach ($message->content as $part) {
            if (!\is_array($part) || 'image_ref' !== ($part['type'] ?? null)) {
                continue;
            }

            $path = $part['path'] ?? null;
            if (!\is_string($path) || '' === $path) {
                continue;
            }

            $readability[$path] = is_file($path) && is_readable($path);
        }

        return $readability;
    }

    private function messagesEqual(AgentMessage $left, AgentMessage $right): bool
    {
        return $left->role === $right->role
            && $left->content === $right->content
            && $left->name === $right->name
            && $left->toolCallId === $right->toolCallId
            && $left->toolName === $right->toolName
            && $left->details === $right->details
            && $left->isError === $right->isError
            && $left->metadata === $right->metadata
            && $this->timestampsEqual($left->timestamp, $right->timestamp);
    }

    private function timestampsEqual(?\DateTimeImmutable $left, ?\DateTimeImmutable $right): bool
    {
        if ($left === $right) {
            return true;
        }

        if (null === $left || null === $right) {
            return false;
        }

        return $left->format(\DATE_ATOM) === $right->format(\DATE_ATOM);
    }

    /**
     * Convert an AgentMessage into one or more Symfony MessageInterface instances.
     *
     * Most messages produce exactly one Symfony message. Tool messages that
     * carry image_ref content parts produce two:
     * 1. ToolCallMessage with text tool output (normal tool result)
     * 2. UserMessage with Image attachment (synthetic follow-up for vision)
     *
     * The top-level toMessageBag() method defers synthetic image messages
     * until the end of a consecutive tool-message batch so providers that
     * require all tool responses to immediately follow an assistant tool-call
     * message still receive a valid sequence.
     *
     * @return list<MessageInterface>
     */
    private function convertAgentMessage(AgentMessage $message): array
    {
        $textContent = $this->contentToText($message->content);
        $imageRefParts = $this->extractImageRefParts($message->content);
        $hasText = false;
        foreach ($message->content as $part) {
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $hasText = true;
                break;
            }
        }

        // Skip thinking-only assistant messages (no text, no tool calls)
        // that were erroneously persisted from provider reasoning-only
        // responses. These cannot be serialized as valid provider
        // requests — DeepSeek rejects {content: null, reasoning_content:
        // "..."} because content or tool_calls must be set.
        //
        // This is the replay/resume safety net for sessions that were
        // recorded before ExecuteLlmStepWorker started converting
        // thinking-only responses to errors.
        if ('assistant' === $message->role
            && '' === $textContent
            && !$hasText
            && [] === ($message->metadata['tool_calls'] ?? [])
            && null !== $message->details
            && \is_string($message->details['thinking'] ?? null)
        ) {
            return [];
        }

        $converted = match ($message->role) {
            'system' => [Message::forSystem($textContent)],
            'assistant' => [$this->buildAssistantMessage($textContent, $message)],
            'tool' => $this->buildToolMessages($textContent, $message, $imageRefParts),
            default => [Message::ofUser($this->userText($message, $textContent))],
        };

        if (($converted[0] ?? null) instanceof AssistantMessage && [] === $converted[0]->getContent()) {
            return [];
        }

        // Apply metadata to the first message (typically the primary message)
        if ([] !== $message->metadata && isset($converted[0])) {
            $converted[0]->getMetadata()->set($message->metadata);
        }

        return $converted;
    }

    /**
     * @param list<array<string, mixed>> $imageRefParts
     *
     * @return list<MessageInterface>
     */
    private function buildToolMessages(string $textContent, AgentMessage $message, array $imageRefParts): array
    {
        $messages = [];

        $toolCallId = $message->toolCallId;
        $toolName = $message->toolName;

        // 1. Produce the normal ToolCallMessage (text-only)
        if (null === $toolCallId || null === $toolName) {
            $messages[] = Message::ofUser($this->userText($message, $textContent));
        } else {
            $arguments = \is_array($message->details['arguments'] ?? null)
                ? $message->details['arguments']
                : [];

            $content = '' !== $textContent
                ? $textContent
                : $this->stringify($message->details ?? ['is_error' => $message->isError]);

            $messages[] = Message::ofToolCall(new ToolCall($toolCallId, $toolName, $arguments), $content);
        }

        // 2. For each image_ref part, add a synthetic UserMessage.
        //    Tool results are text on the wire for tool-role messages;
        //    vision models need a user-role message with Image content.
        //    ImageGatingConvertHook strips image_ref when the model lacks vision.
        //
        //    Image capability gating is handled upstream by
        //    ImageGatingConvertHook; this converter always attaches
        //    images when the file is available.
        foreach ($imageRefParts as $imageRef) {
            $path = $imageRef['path'] ?? null;
            $mediaType = $imageRef['media_type'] ?? 'unknown';
            $width = $imageRef['width'] ?? '?';
            $height = $imageRef['height'] ?? '?';
            $bytes = $imageRef['bytes'] ?? 0;

            if (!\is_string($path) || '' === $path || !is_file($path) || !is_readable($path)) {
                // Image file is missing or unreadable — emit text placeholder
                $messages[] = Message::ofUser(\sprintf(
                    '[Tool result image for view_image: %s (%s)]',
                    $path ?? '(deleted)',
                    $mediaType,
                ));

                continue;
            }

            // Create a UserMessage with a text intro plus the real Image attachment.
            // Image::fromFile($path) lazily reads the file; the data URL is only built
            // at provider normalization time, so no image bytes are in memory
            // during session persistence.
            $introText = new Text(\sprintf(
                'Tool result image for view_image: %s (%s, %sx%s, %d bytes)',
                $path,
                $mediaType,
                $width,
                $height,
                $bytes,
            ));

            $messages[] = Message::ofUser($introText, Image::fromFile($path));
        }

        return $messages;
    }

    /**
     * @return list<ToolCall>|null
     */
    private function assistantToolCalls(AgentMessage $message): ?array
    {
        $rawToolCalls = \is_array($message->metadata['tool_calls'] ?? null)
            ? $message->metadata['tool_calls']
            : null;

        if (null === $rawToolCalls) {
            return null;
        }

        $toolCalls = [];
        foreach ($rawToolCalls as $rawToolCall) {
            if (!\is_array($rawToolCall)) {
                continue;
            }

            $id = $rawToolCall['id'] ?? null;
            $name = $rawToolCall['name'] ?? null;

            if (!\is_string($id) || !\is_string($name)) {
                continue;
            }

            $toolCalls[] = new ToolCall(
                $id,
                $name,
                \is_array($rawToolCall['arguments'] ?? null) ? $rawToolCall['arguments'] : [],
            );
        }

        return [] === $toolCalls ? null : $toolCalls;
    }

    /**
     * Builds an AssistantMessage using the 0.9 ContentInterface-based constructor.
     */
    private function buildAssistantMessage(string $textContent, AgentMessage $message): AssistantMessage
    {
        $contentParts = [];
        $thinkingContent = \is_string($message->details['thinking'] ?? null) ? $message->details['thinking'] : null;
        $thinkingSignature = \is_string($message->details['thinking_signature'] ?? null) ? $message->details['thinking_signature'] : null;
        $thinkingSignatures = \is_array($message->details['thinking_signatures'] ?? null)
            ? $message->details['thinking_signatures']
            : [];
        $hasOrderedThinkingParts = false;
        $hasOrderedToolParts = false;
        $hasTextPart = false;
        $toolCalls = $this->assistantToolCalls($message);
        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if ('tool_call' === ($part['type'] ?? null)) {
                $hasOrderedToolParts = true;
            }
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $hasTextPart = true;
            }
            if ('thinking' === ($part['type'] ?? null) || isset($part['text_signature'])) {
                $hasOrderedThinkingParts = true;
            }
        }

        if ($hasOrderedThinkingParts || $hasOrderedToolParts) {
            foreach ($message->content as $part) {
                if (!\is_array($part)) {
                    continue;
                }

                $type = $part['type'] ?? null;
                if ('tool_call' === $type) {
                    $index = $part['tool_call_index'] ?? null;
                    if (\is_int($index) && isset($toolCalls[$index])) {
                        $contentParts[] = $toolCalls[$index];
                    }
                    continue;
                }
                if ('text' === $type) {
                    $text = $part['text'] ?? null;
                    $signature = \is_string($part['text_signature'] ?? null) ? $part['text_signature'] : null;
                    if (\is_string($text)) {
                        $contentParts[] = new Text($text, $signature);
                    }
                    continue;
                }

                if ('thinking' !== $type) {
                    continue;
                }

                $signature = \is_string($part['thinking_signature'] ?? null) ? $part['thinking_signature'] : null;
                $partText = $part['text'] ?? null;
                $attachDisplayThinking = !\is_string($partText) && null !== $thinkingContent;
                $contentParts[] = new Thinking(
                    content: \is_string($partText) ? $partText : ($attachDisplayThinking ? $thinkingContent : ''),
                    signature: \is_string($signature) && '' !== $signature ? $signature : null,
                );
                if ($attachDisplayThinking) {
                    $thinkingContent = null;
                }
            }

            if ([] === $contentParts && '' !== $textContent) {
                $contentParts[] = new Text($textContent);
            }
        } else {
            if ([] !== $thinkingSignatures) {
                $firstSignature = true;
                foreach ($thinkingSignatures as $signature) {
                    if (!\is_string($signature)) {
                        continue;
                    }
                    $contentParts[] = new Thinking(
                        content: $firstSignature ? $thinkingContent ?? '' : '',
                        signature: $signature,
                    );
                    $firstSignature = false;
                }
            } elseif (null !== $thinkingContent || null !== $thinkingSignature) {
                $contentParts[] = new Thinking(
                    content: $thinkingContent ?? '',
                    signature: $thinkingSignature,
                );
            }

            if ('' !== $textContent || $hasTextPart) {
                $contentParts[] = new Text($textContent);
            }
        }

        if (!$hasOrderedToolParts && null !== $toolCalls) {
            foreach ($toolCalls as $toolCall) {
                $contentParts[] = $toolCall;
            }
        }

        return new AssistantMessage(...$contentParts);
    }

    private function userText(AgentMessage $message, string $textContent): string
    {
        if ($message->isCustomRole()) {
            return \sprintf('[%s] %s', $message->role, $textContent);
        }

        return $textContent;
    }

    /**
     * @param array<int, array<string, mixed>> $content
     *
     * @return string Concatenated text from all 'text' content parts
     */
    private function contentToText(array $content): string
    {
        $parts = [];

        foreach ($content as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $text = $contentPart['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Extract image_ref content parts from an AgentMessage's content array.
     *
     * @param array<int, array<string, mixed>> $content
     *
     * @return list<array<string, mixed>>
     */
    private function extractImageRefParts(array $content): array
    {
        $imageRefs = [];

        foreach ($content as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            if (ToolResultType::IMAGE_REF === ($contentPart['type'] ?? null)) {
                $imageRefs[] = $contentPart;
            }
        }

        return $imageRefs;
    }

    private function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }
}
