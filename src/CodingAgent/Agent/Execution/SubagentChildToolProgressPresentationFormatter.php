<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Safe tool-line and assistant excerpt formatting shared by poll-based and deferred projections.
 */
final class SubagentChildToolProgressPresentationFormatter
{
    public const int MAX_ARG_VALUE_LEN = 72;
    public const int MAX_ASSISTANT_EXCERPT = 220;

    /**
     * @return array<string, mixed>
     */
    public function normalizeToolArguments(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if (\is_string($raw) && '' !== $raw) {
            try {
                $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
                // Malformed tool argument JSON must not break progress projection.
                return [];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $args
     */
    public function formatToolDisplayLine(string $name, array $args): string
    {
        $pairs = $this->safeArgPairs($name, $args);
        if ([] === $pairs) {
            return $name;
        }

        return $name.': '.implode(', ', $pairs);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return list<string>
     */
    public function safeArgPairs(string $toolName, array $args): array
    {
        $keys = match ($toolName) {
            'read', 'write', 'edit' => ['path'],
            'bash', 'shell' => ['command', 'cmd'],
            'grep' => ['pattern', 'path'],
            'glob', 'find' => ['pattern', 'path'],
            default => ['path', 'command', 'cmd', 'query', 'pattern', 'file'],
        };

        $pairs = [];
        foreach ($keys as $key) {
            if (!isset($args[$key]) || !\is_scalar($args[$key])) {
                continue;
            }
            $value = (string) $args[$key];
            if ('' === $value) {
                continue;
            }
            $pairs[] = $key.'="'.$this->truncateLine($value, self::MAX_ARG_VALUE_LEN).'"';
            if (\count($pairs) >= 2) {
                break;
            }
        }

        return $pairs;
    }

    public function assistantTextFromMessage(AgentMessage $message): string
    {
        return $this->textFromMessage($message);
    }

    /**
     * @param array<string, mixed> $assistantPayload
     */
    public function assistantTextFromPayload(array $assistantPayload): string
    {
        $msg = AgentMessage::fromPayload($assistantPayload);
        if (null === $msg || 'assistant' !== $msg->role) {
            return '';
        }

        return $this->textFromMessage($msg);
    }

    public function assistantExcerptFromText(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        return $this->truncateLine($text, self::MAX_ASSISTANT_EXCERPT);
    }

    public function truncateLine(string $text, int $max): string
    {
        $normalized = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (mb_strlen($normalized) <= $max) {
            return $normalized;
        }

        return mb_substr($normalized, 0, $max - 1).'…';
    }

    private function textFromMessage(AgentMessage $message): string
    {
        $parts = [];
        foreach ($message->content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if ('text' === ($part['type'] ?? null) && \is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode(' ', $parts));
    }
}
