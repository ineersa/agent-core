<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Joins the non-empty string `text` of message content parts with newlines. */
final class MessageContentText
{
    /**
     * @param array<mixed> $content
     * @param bool         $textPartsOnly when true, parts whose `type` is not `text` are skipped
     */
    public static function join(array $content, bool $textPartsOnly): string
    {
        $parts = [];
        foreach ($content as $part) {
            if (!\is_array($part)) {
                continue;
            }
            if ($textPartsOnly && 'text' !== ($part['type'] ?? null)) {
                continue;
            }
            $text = $part['text'] ?? null;
            if (\is_string($text) && '' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }
}
