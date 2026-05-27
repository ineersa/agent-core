<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
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
 * Supports image_ref content parts: when a tool AgentMessage contains
 * an image_ref content part, the converter emits a synthetic UserMessage
 * containing a real Symfony AI Image attachment after the normal tool
 * call message. This provides the Pi-style fallback for multimodal tool
 * results where the Symfony AI ToolCallMessage string-only content
 * cannot directly carry image data.
 */
final class AgentMessageConverter
{
    /**
     * Content part type for image references that should be converted
     * into real Symfony AI Image attachments.
     */
    private const string IMAGE_REF_TYPE = 'image_ref';
    /**
     * Optional checker to determine whether the active target model
     * supports image inputs. When null or when the checker reports
     * no support, synthetic image attachments are replaced with
     * text placeholders.
     */
    private ?ImageCapabilityCheckerInterface $imageCapabilityChecker = null;

    /**
     * @param list<AgentMessage> $agentMessages
     * @param string             $modelName     Target model identifier for
     *                                          capability gating. Empty string
     *                                          means "unknown" — images are
     *                                          attached when the checker is
     *                                          unavailable (backward compat)
     *                                          or gated when the checker returns
     *                                          false.
     */
    public function toMessageBag(array $agentMessages, string $modelName = ''): MessageBag
    {
        $messages = [];
        $pendingSyntheticToolMessages = [];
        $imagesSupported = $this->isImageSupported($modelName);

        foreach ($agentMessages as $agentMessage) {
            if ('tool' !== $agentMessage->role && [] !== $pendingSyntheticToolMessages) {
                array_push($messages, ...$pendingSyntheticToolMessages);
                $pendingSyntheticToolMessages = [];
            }

            $convertedMessages = $this->convertAgentMessage($agentMessage, $imagesSupported);

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

        return new MessageBag(...$messages);
    }

    /**
     * Set the optional image capability checker.
     *
     * When set, the converter will check whether the target model
     * supports images before emitting synthetic Image attachments.
     * Without a checker, images are always attached (backward-compatible
     * default).
     */
    public function setImageCapabilityChecker(?ImageCapabilityCheckerInterface $checker): void
    {
        $this->imageCapabilityChecker = $checker;
    }

    /**
     * Convert an AgentMessage into one or more Symfony MessageInterface instances.
     *
     * Most messages produce exactly one Symfony message. Tool messages that
     * carry image_ref content parts produce two:
     * 1. ToolCallMessage with the text content (normal tool result)
     * 2. UserMessage with Image attachment (synthetic follow-up)
     *
     * The top-level toMessageBag() method defers synthetic image messages
     * until the end of a consecutive tool-message batch so providers that
     * require all tool responses to immediately follow an assistant tool-call
     * message still receive a valid sequence.
     *
     * @return list<MessageInterface>
     */
    private function convertAgentMessage(AgentMessage $message, bool $imagesSupported = true): array
    {
        $result = [];

        $textContent = $this->contentToText($message->content);
        $imageRefParts = $this->extractImageRefParts($message->content);

        $converted = match ($message->role) {
            'system' => [Message::forSystem($textContent)],
            'assistant' => [$this->buildAssistantMessage($textContent, $message)],
            'tool' => $this->buildToolMessages($textContent, $message, $imageRefParts, $imagesSupported),
            default => [Message::ofUser($this->userText($message, $textContent))],
        };

        $result = $converted;

        // Apply metadata to the first message (typically the primary message)
        if ([] !== $message->metadata && isset($result[0])) {
            $result[0]->getMetadata()->set($message->metadata);
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $imageRefParts
     *
     * @return list<MessageInterface>
     */
    private function buildToolMessages(string $textContent, AgentMessage $message, array $imageRefParts, bool $imagesSupported): array
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
        //    Symfony AI ToolCallMessage is string-only, so we cannot
        //    embed images in the tool result itself. Instead we emit
        //    a follow-up user message that the provider normalizers
        //    will serialize as proper image content.
        //
        //    When the target model does not support image inputs
        //    ($imagesSupported = false), emit a text placeholder
        //    instead of a real Image attachment to avoid sending
        //    image bytes to a non-vision model.
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

            if (!$imagesSupported) {
                // Model does not support image inputs — emit text placeholder
                $messages[] = Message::ofUser(\sprintf(
                    '[Tool result image: %s (%s, %sx%s, %d bytes). '
                    .'Actual image omitted — the active model does not support images.]',
                    $path,
                    $mediaType,
                    $width,
                    $height,
                    $bytes,
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

            if (self::IMAGE_REF_TYPE === ($contentPart['type'] ?? null)) {
                $imageRefs[] = $contentPart;
            }
        }

        return $imageRefs;
    }

    /**
     * Determine whether the named model supports image inputs.
     *
     * - No checker configured: attach images by default (backward compat).
     * - Checker configured, empty model name: images are NOT attached
     *   because we cannot confirm the target model's capability.
     * - Checker configured, non-empty model name: delegate to checker.
     */
    private function isImageSupported(string $modelName): bool
    {
        if (null === $this->imageCapabilityChecker) {
            return true;
        }

        if ('' === $modelName) {
            return false;
        }

        return $this->imageCapabilityChecker->supportsImages($modelName);
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
