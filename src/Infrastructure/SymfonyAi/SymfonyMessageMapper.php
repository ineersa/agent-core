<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;

final class SymfonyMessageMapper
{
    /**
     * Converts an array of provider messages into a MessageBag instance.
     *
     * @param list<AgentMessage|object> $messages
     */
    public function toMessageBag(array $messages): MessageBag
    {
        $llmMessages = [];

        foreach ($messages as $message) {
            if ($message instanceof AgentMessage) {
                $llmMessages[] = $this->convertAgentMessage($message);

                continue;
            }

            if (\is_object($message)) {
                $llmMessages[] = $message;
            }
        }

        return new MessageBag($llmMessages);
    }

    /**
     * Transforms a MessageBag into a provider-compatible array or object.
     *
     * @return array<string, mixed>|object
     */
    public function toProviderInput(MessageBag $messageBag): array|object
    {
        $messages = $messageBag->all();

        if ([] === $messages) {
            return ['messages' => []];
        }

        if (1 === \count($messages) && $this->isNativeMessageBag($messages[0])) {
            return $messages[0];
        }

        if ($this->supportsNativeMessageApi()) {
            /** @var class-string $messageBagClass */
            $messageBagClass = 'Symfony\\AI\\Platform\\Message\\MessageBag';

            try {
                return new $messageBagClass(...$messages);
            } catch (\Throwable) {
                // Fallback to normalized array payload if the message objects are not native Symfony message DTOs.
            }
        }

        return [
            'messages' => array_map($this->normalizeProviderMessage(...), $messages),
        ];
    }

    private function convertAgentMessage(AgentMessage $message): object
    {
        if (!$this->supportsNativeMessageApi()) {
            return $this->toGenericMessageObject($message);
        }

        /** @var class-string $messageFactory */
        $messageFactory = 'Symfony\\AI\\Platform\\Message\\Message';
        $textContent = $this->contentToText($message->content);

        return match ($message->role) {
            'system' => $messageFactory::forSystem($textContent),
            'assistant' => $messageFactory::ofAssistant(
                '' !== $textContent ? $textContent : null,
                $this->assistantToolCalls($message),
            ),
            'tool' => $this->toNativeToolCallMessage($message, $textContent),
            default => $messageFactory::ofUser($this->userText($message, $textContent)),
        };
    }

    private function toNativeToolCallMessage(AgentMessage $message, string $textContent): object
    {
        /** @var class-string $messageFactory */
        $messageFactory = 'Symfony\\AI\\Platform\\Message\\Message';
        /** @var class-string $toolCallClass */
        $toolCallClass = 'Symfony\\AI\\Platform\\Result\\ToolCall';

        $toolCallId = $message->toolCallId;
        $toolName = $message->toolName;
        if (null === $toolCallId || null === $toolName) {
            return $messageFactory::ofUser($this->userText($message, $textContent));
        }

        $arguments = \is_array($message->details['arguments'] ?? null)
            ? $message->details['arguments']
            : [];

        $toolCall = new $toolCallClass($toolCallId, $toolName, $arguments);

        $content = '' !== $textContent
            ? $textContent
            : $this->stringify($message->details ?? ['is_error' => $message->isError]);

        return $messageFactory::ofToolCall($toolCall, $content);
    }

    /**
     * Extracts tool call data from an agent message if present.
     *
     * @return list<object>|null
     */
    private function assistantToolCalls(AgentMessage $message): ?array
    {
        if (!class_exists('Symfony\\AI\\Platform\\Result\\ToolCall')) {
            return null;
        }

        $rawToolCalls = \is_array($message->metadata['tool_calls'] ?? null)
            ? $message->metadata['tool_calls']
            : null;

        if (null === $rawToolCalls) {
            return null;
        }

        /** @var class-string $toolCallClass */
        $toolCallClass = 'Symfony\\AI\\Platform\\Result\\ToolCall';

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

            $toolCalls[] = new $toolCallClass(
                $id,
                $name,
                \is_array($rawToolCall['arguments'] ?? null) ? $rawToolCall['arguments'] : [],
            );
        }

        return [] === $toolCalls ? null : $toolCalls;
    }

    private function toGenericMessageObject(AgentMessage $message): object
    {
        $payload = [
            'role' => $message->role,
            'content' => $message->content,
            'is_error' => $message->isError,
        ];

        if (null !== $message->toolCallId) {
            $payload['tool_call_id'] = $message->toolCallId;
        }

        if (null !== $message->toolName) {
            $payload['tool_name'] = $message->toolName;
        }

        if (null !== $message->details) {
            $payload['details'] = $message->details;
        }

        if ([] !== $message->metadata) {
            $payload['metadata'] = $message->metadata;
        }

        return (object) $payload;
    }

    private function supportsNativeMessageApi(): bool
    {
        return class_exists('Symfony\\AI\\Platform\\Message\\Message')
            && class_exists('Symfony\\AI\\Platform\\Message\\MessageBag');
    }

    private function isNativeMessageBag(object $message): bool
    {
        return 'Symfony\\AI\\Platform\\Message\\MessageBag' === $message::class;
    }

    private function userText(AgentMessage $message, string $textContent): string
    {
        if ($message->isCustomRole()) {
            return \sprintf('[%s] %s', $message->role, $textContent);
        }

        return $textContent;
    }

    /**
     * Converts a content array into a single plain text string.
     *
     * @param array<int, array<string, mixed>> $content
     */
    private function contentToText(array $content): string
    {
        $parts = [];

        foreach ($content as $contentPart) {
            if (!\is_array($contentPart)) {
                continue;
            }

            $text = $contentPart['text'] ?? null;
            if (!\is_string($text) || '' === $text) {
                continue;
            }

            $parts[] = $text;
        }

        return implode("\n", $parts);
    }

    /**
     * Normalizes a provider message object into a standardized array structure.
     *
     * @return array<string, mixed>
     */
    private function normalizeProviderMessage(object $message): array
    {
        if ($message instanceof \stdClass) {
            return (array) $message;
        }

        if (method_exists($message, 'getRole') && method_exists($message, 'getContent')) {
            $role = $message->getRole();
            if (\is_object($role) && property_exists($role, 'value')) {
                $role = $role->value;
            }

            $normalized = [
                'role' => \is_string($role) ? $role : (string) $role,
                'content' => $this->normalizeContent($message->getContent()),
            ];

            if (method_exists($message, 'getToolCall')) {
                $toolCall = $message->getToolCall();
                if (\is_object($toolCall) && method_exists($toolCall, 'getId') && method_exists($toolCall, 'getName')) {
                    $normalized['tool_call'] = [
                        'id' => (string) $toolCall->getId(),
                        'name' => (string) $toolCall->getName(),
                    ];
                }
            }

            return $normalized;
        }

        return ['raw' => $this->stringify($message)];
    }

    private function normalizeContent(mixed $content): mixed
    {
        if (\is_scalar($content) || null === $content) {
            return $content;
        }

        if (\is_array($content)) {
            return $content;
        }

        if (\is_object($content) && method_exists($content, 'getText')) {
            return $content->getText();
        }

        return $this->stringify($content);
    }

    private function stringify(mixed $value): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_scalar($value) || null === $value) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        $encoded = json_encode($value);

        return false === $encoded ? '{}' : $encoded;
    }
}
