<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Line-budget preview for transcript card bodies (tool args, diff patches, write content).
 *
 * When {@see $lineLimit} is <= 0, preview is disabled (all lines returned).
 * When {@see TranscriptDisplayState::$previewableBlocksExpanded} is true, all lines are returned.
 */
final readonly class TranscriptLinePreviewService
{
    /**
     * @param list<string> $lines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    public function apply(
        array $lines,
        int $lineLimit,
        bool $fullRender,
        TranscriptDisplayState $displayState,
    ): array {
        if ($fullRender) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        if ($lineLimit <= 0 || \count($lines) <= $lineLimit) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        if ($displayState->previewableBlocksExpanded) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        $remaining = \count($lines) - $lineLimit;
        $ellipsis = \sprintf('… %d more line%s', $remaining, 1 === $remaining ? '' : 's');

        return [
            'lines' => \array_slice($lines, 0, $lineLimit),
            'ellipsis' => $ellipsis,
        ];
    }
}
