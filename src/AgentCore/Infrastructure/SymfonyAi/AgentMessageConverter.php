<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ToolResultType;
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
 */
final class AgentMessageConverter
{
    /**
     * Convert a list of AgentMessages into a Symfony AI MessageBag.
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

        return new MessageBag(...$messages);
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
