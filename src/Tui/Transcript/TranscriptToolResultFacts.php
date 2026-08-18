<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Symfony\Component\Yaml\Yaml;

/**
 * Result-body presentation facts for tool results.
 *
 * Owns whether a tool result renders in full (error/cancel/timeout), its
 * display body text (including successful edit result compaction), and
 * truthiness of string/numeric meta flags. Consumed by the pairing policy's
 * candidate scoring and by {@see TranscriptToolRenderer}; pairing/suppression
 * decisions stay in {@see TranscriptToolPresentationPolicy}.
 */
final readonly class TranscriptToolResultFacts
{
    /**
     * Error, cancelled, and timed_out tool results bypass preview so diagnostics are not hidden.
     *
     * Projection currently sets is_error for cancelled/timed_out as well; color still keys off is_error when full.
     */
    public function toolResultIsFullRender(TranscriptBlock $block): bool
    {
        return $this->metaIsTruthy($block->meta['is_error'] ?? false)
            || $this->metaIsTruthy($block->meta['cancelled'] ?? false)
            || $this->metaIsTruthy($block->meta['timed_out'] ?? false);
    }

    public function toolResultBodyText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;
        if (\is_string($result) && '' !== $result) {
            return $this->compactSuccessfulEditWriteResultBody($block, $result);
        }
        if (\is_scalar($result) && '' !== (string) $result) {
            return (string) $result;
        }
        if (\is_array($result) || \is_object($result)) {
            return trim(Yaml::dump($result, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        $text = $block->text;
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName && $text === $toolName) {
            return '';
        }
        if ('Tool result' === $text) {
            return '';
        }

        return $text;
    }

    public function metaIsTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    private function compactSuccessfulEditWriteResultBody(TranscriptBlock $block, string $result): string
    {
        if ($this->toolResultIsFullRender($block)) {
            return $result;
        }

        $toolName = $block->meta['tool_name'] ?? null;
        if (!\is_string($toolName) || 'edit' !== $toolName) {
            // write (and other) successful tool results are already compact status lines.
            return $result;
        }

        $marker = 'Updated file context:';
        $pos = strpos($result, $marker);
        if (false !== $pos) {
            return rtrim(substr($result, 0, $pos));
        }

        return $result;
    }
}
