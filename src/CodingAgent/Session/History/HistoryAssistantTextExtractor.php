<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\CodingAgent\Runtime\Protocol\AssistantMessageContentMapper;

/** Final text from a completed response, never a tool step or reasoning summary. */
final class HistoryAssistantTextExtractor
{
    /** @param array<string, mixed> $payload */
    public static function extract(array $payload): string
    {
        $message = $payload['assistant_message'] ?? null;
        if (!\is_array($message) || 'assistant' !== ($message['role'] ?? null) || [] !== ($message['tool_calls'] ?? [])) {
            return '';
        }
        foreach ($message['content'] ?? [] as $part) {
            if ('tool_call' === ($part['type'] ?? null)) {
                return '';
            }
        }
        $parts = AssistantMessageContentMapper::map($message, '', '') ?? $message['content'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if ('text' === ($part['type'] ?? null) && \in_array($part['phase'] ?? null, [null, 'final_answer'], true)) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return $text;
    }
}
