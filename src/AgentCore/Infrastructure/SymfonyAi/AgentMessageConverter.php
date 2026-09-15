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
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Converts AgentCore domain AgentMessages into Symfony AI MessageBag
 * for provider request construction.
 *
 * Owns one worker-local active MessageBag and the target model it was built
 * for. Exact same-target contexts reuse that bag. Appends convert only new
 * AgentMessages. Target switches and replaced prefixes rebuild once. Generic
 * {@see toMessageBag()} callers always get a fresh bag and do not touch the
 * retained active bag.
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
 * resets leave the disposable active bag intact across ExecuteLlmStep messages.
 */
final class AgentMessageConverter
{
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

    private ?int $incompleteToolBatchStart = null;

    private ?int $incompleteToolBatchStableBagCount = null;

    public function __construct(
        private readonly ConversationHistoryConversion $historyConversion = new ConversationHistoryConversion(),
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
     * Same target + unchanged exact source prefix reuses the bag. Appends
     * convert only the suffix (and at most an open trailing tool batch).
     * Target changes, compaction, history edits, and image readability
     * changes rebuild. Returns the active bag itself; request shapers create
     * replacements rather than mutating it in place.
     *
     * @param list<AgentMessage> $agentMessages
     */
    public function toMessageBagForTarget(array $agentMessages, string $targetModel): MessageBag
    {
        if ('' === $targetModel) {
            return $this->toMessageBag($agentMessages);
        }

        if (null !== $this->activeBag
            && $this->activeTargetModel === $targetModel
            && $this->isExactPrefix($this->activeSourceMessages, $agentMessages)
            && $this->imageReadabilityMatchesPrefix($agentMessages)
        ) {
            $cachedCount = \count($this->activeSourceMessages);
            if ($cachedCount === \count($agentMessages)) {
                return $this->activeBag;
            }

            $this->appendActiveProjection($agentMessages, $targetModel);

            return $this->activeBag;
        }

        $this->rebuildActiveProjection($agentMessages, $targetModel);

        return $this->activeBag;
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

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function rebuildActiveProjection(array $agentMessages, string $targetModel): void
    {
        $idMap = [];
        $usedIds = [];
        $converted = $this->historyConversion->convertMessages($agentMessages, $targetModel, $idMap, $usedIds);
        $bag = new MessageBag(...$this->convertAgentMessages($converted));

        $incompleteStart = $this->trailingIncompleteToolBatchStart($converted);
        $stableBagCount = null;
        if (null !== $incompleteStart) {
            // Derive the stable bag boundary from the already-built bag and only
            // the open trailing tool batch. Never reconvert the stable prefix.
            $openBatch = \array_slice($converted, $incompleteStart);
            $stableBagCount = \count($bag->getMessages())
                - \count($this->convertAgentMessages($openBatch));
        }

        $this->activeBag = $bag;
        $this->activeTargetModel = $targetModel;
        $this->activeSourceMessages = $agentMessages;
        $this->idMap = $idMap;
        $this->usedIds = $usedIds;
        $this->imageReadability = array_map(
            $this->imageReadabilityForMessage(...),
            $agentMessages,
        );
        $this->incompleteToolBatchStart = $incompleteStart;
        $this->incompleteToolBatchStableBagCount = $stableBagCount;
    }

    /**
     * @param list<AgentMessage> $agentMessages
     */
    private function appendActiveProjection(array $agentMessages, string $targetModel): void
    {
        \assert(null !== $this->activeBag);

        $cachedCount = \count($this->activeSourceMessages);
        $suffixSource = \array_slice($agentMessages, $cachedCount);
        $suffixConverted = $this->historyConversion->convertMessages(
            $suffixSource,
            $targetModel,
            $this->idMap,
            $this->usedIds,
        );

        $bag = $this->activeBag;
        $rebuildFrom = $this->incompleteToolBatchStart;
        if (null !== $rebuildFrom) {
            $stableCount = $this->incompleteToolBatchStableBagCount ?? 0;
            $bag = new MessageBag(...\array_slice($bag->getMessages(), 0, $stableCount));
            $openPrefix = \array_slice($this->activeSourceMessages, $rebuildFrom);
            $openPrefixConverted = $this->historyConversion->convertMessages(
                $openPrefix,
                $targetModel,
                $this->idMap,
                $this->usedIds,
            );
            $this->appendConvertedMessages($bag, array_merge($openPrefixConverted, $suffixConverted));
            $this->activeBag = $bag;
        } else {
            $this->appendConvertedMessages($bag, $suffixConverted);
        }

        $boundarySource = array_merge($this->activeSourceMessages, $suffixSource);
        $incompleteStart = $this->trailingIncompleteToolBatchStart($boundarySource);
        $stableBagCount = null;
        if (null !== $incompleteStart) {
            // Derive the stable bag boundary from the already-built bag and only
            // the open trailing tool batch. Never reconvert the stable prefix.
            $openBatch = $this->historyConversion->convertMessages(
                \array_slice($boundarySource, $incompleteStart),
                $targetModel,
                $this->idMap,
                $this->usedIds,
            );
            $stableBagCount = \count($this->activeBag->getMessages())
                - \count($this->convertAgentMessages($openBatch));
        }

        $this->activeTargetModel = $targetModel;
        $this->activeSourceMessages = $agentMessages;
        $this->imageReadability = array_map(
            $this->imageReadabilityForMessage(...),
            $agentMessages,
        );
        $this->incompleteToolBatchStart = $incompleteStart;
        $this->incompleteToolBatchStableBagCount = $stableBagCount;
    }

    /**
     * @param list<AgentMessage> $convertedMessages
     */
    private function appendConvertedMessages(MessageBag $bag, array $convertedMessages): void
    {
        foreach ($this->convertAgentMessages($convertedMessages) as $message) {
            $bag->add($message);
        }
    }

    /**
     * When the active AgentMessage list ends inside a consecutive tool-result
     * batch, return the start index of that trailing incomplete batch so append
     * can rebuild only that region. Synthetic image UserMessages are deferred
     * until the tool batch closes, so an open trailing batch cannot keep its
     * Symfony messages as-is when later tool results arrive.
     *
     * @param list<AgentMessage> $convertedMessages
     */
    private function trailingIncompleteToolBatchStart(array $convertedMessages): ?int
    {
        $count = \count($convertedMessages);
        if (0 === $count || 'tool' !== $convertedMessages[$count - 1]->role) {
            return null;
        }

        $start = $count - 1;
        while ($start > 0 && 'tool' === $convertedMessages[$start - 1]->role) {
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

        if ('' !== $textContent) {
            $contentParts[] = new Text($textContent);
        }

        $thinkingContent = \is_string($message->details['thinking'] ?? null) ? $message->details['thinking'] : null;
        $thinkingSignature = \is_string($message->details['thinking_signature'] ?? null) ? $message->details['thinking_signature'] : null;

        if (null !== $thinkingContent || null !== $thinkingSignature) {
            $contentParts[] = new Thinking(
                content: $thinkingContent ?? '',
                signature: $thinkingSignature,
            );
        }

        $toolCalls = $this->assistantToolCalls($message);
        if (null !== $toolCalls) {
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
