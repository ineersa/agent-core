<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Result\ToolCall;

final class AgentMessageConverter
{
    /**
     * @param list<AgentMessage> $agentMessages
     */
    public function toMessageBag(array $agentMessages): MessageBag
    {
        $messages = [];

        foreach ($agentMessages as $agentMessage) {
            $messages[] = $this->convertAgentMessage($agentMessage);
        }

        return new MessageBag(...$messages);
    }

    private function convertAgentMessage(AgentMessage $message): MessageInterface
    {
        $textContent = $this->contentToText($message->content);

        $converted = match ($message->role) {
            'system' => Message::forSystem($textContent),
            'assistant' => new AssistantMessage(
                content: '' === $textContent ? null : $textContent,
                toolCalls: $this->assistantToolCalls($message),
                thinkingContent: \is_string($message->details['thinking'] ?? null) ? $message->details['thinking'] : null,
                thinkingSignature: \is_string($message->details['thinking_signature'] ?? null) ? $message->details['thinking_signature'] : null,
            ),
            'tool' => $this->toToolCallMessage($message),
            default => Message::ofUser($this->userText($message, $textContent)),
        };

        if ([] !== $message->metadata) {
            $converted->getMetadata()->set($message->metadata);
        }

        return $converted;
    }

    private function toToolCallMessage(AgentMessage $message): MessageInterface
    {
        $toolCallId = $message->toolCallId;
        $toolName = $message->toolName;
        $textContent = $this->contentToText($message->content);

        if (null === $toolCallId || null === $toolName) {
            return Message::ofUser($this->userText($message, $textContent));
        }

        $arguments = \is_array($message->details['arguments'] ?? null)
            ? $message->details['arguments']
            : [];

        $content = '' !== $textContent
            ? $textContent
            : $this->stringify($message->details ?? ['is_error' => $message->isError]);

        return Message::ofToolCall(new ToolCall($toolCallId, $toolName, $arguments), $content);
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

    private function userText(AgentMessage $message, string $textContent): string
    {
        if ($message->isCustomRole()) {
            return \sprintf('[%s] %s', $message->role, $textContent);
        }

        return $textContent;
    }

    /**
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
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
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
