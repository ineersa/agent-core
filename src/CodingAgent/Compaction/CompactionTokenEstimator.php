<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Symfony\Component\String\UnicodeString;

/**
 * Token estimation from model-facing text using Unicode-safe character
 * counts and a 3.25 chars/token divisor.
 *
 * Estimates only model-facing text/content — no JSON envelope, no
 * metadata, no details payload.
 *
 * Follows AgentMessageConverter semantics:
 *   - Concatenates non-empty text content parts with newline.
 *   - For custom roles, includes the `[role] ` prefix.
 *   - For tool messages, uses the text content that would be sent
 *     (not details.raw_result).
 */
final class CompactionTokenEstimator
{
    /** Characters-per-token divisor for estimation */
    private const float CHARS_PER_TOKEN = 3.25;

    /**
     * Estimate token count for a list of AgentMessages.
     *
     * @param list<AgentMessage> $messages
     */
    public function estimateTokens(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += $this->estimateMessageTokens($message);
        }

        return $total;
    }

    /**
     * Estimate tokens for a single AgentMessage using model-facing
     * text only.
     */
    public function estimateMessageTokens(AgentMessage $message): int
    {
        $text = $this->messageToText($message);

        if ('' === $text) {
            return 0;
        }

        $length = (new UnicodeString($text))->length();

        return (int) ceil($length / self::CHARS_PER_TOKEN);
    }

    /**
     * Extract the model-facing text content from an AgentMessage.
     *
     * Follows AgentMessageConverter semantics:
     *   - Concatenates non-empty text content parts with newline.
     *   - For custom roles, includes the `[role] ` prefix.
     *   - For tool messages, uses the text content that would be sent
     *     (not details.raw_result).
     *   - Does not include metadata, details, or JSON envelope.
     */
    public function messageToText(AgentMessage $message): string
    {
        $text = $this->extractContentText($message->content);

        if ($message->isCustomRole()) {
            $text = \sprintf('[%s] %s', $message->role, $text);
        }

        return $text;
    }

    /**
     * Concatenate text from all 'text' content parts.
     *
     * @param array<int, array<string, mixed>> $content
     */
    private function extractContentText(array $content): string
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
}
