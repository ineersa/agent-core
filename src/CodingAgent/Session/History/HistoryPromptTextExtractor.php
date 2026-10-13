<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/** Human prompt text for indexed metadata and exact canonical lookup. */
final class HistoryPromptTextExtractor
{
    /** @param array<string, mixed> $payload */
    public static function extract(string $type, array $payload): string
    {
        if ('run_started' === $type) {
            $inner = \is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
            foreach ([$inner['messages'] ?? [], $payload['messages'] ?? []] as $messages) {
                if (!\is_array($messages)) {
                    continue;
                }
                foreach ($messages as $message) {
                    if (\is_array($message) && 'user' === ($message['role'] ?? null)) {
                        $text = self::content($message['content'] ?? []);
                        if ('' !== $text) {
                            return $text;
                        }
                    }
                }
            }
        } elseif ('agent_command_applied' === $type && \in_array($payload['kind'] ?? null, ['follow_up', 'steer'], true)) {
            $text = \is_string($payload['text'] ?? null) ? $payload['text'] : '';
            if ('' !== $text) {
                return $text;
            }
            $message = $payload['message'] ?? null;

            return \is_array($message) ? self::content($message['content'] ?? []) : '';
        }

        return '';
    }

    private static function content(mixed $content): string
    {
        if (!\is_array($content)) {
            return '';
        }
        $text = '';
        foreach ($content as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        return $text;
    }
}
