<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Default footer bar widget.
 *
 * Renders a compact single line of segments from the FooterDataProvider.
 * Each segment may carry an optional ThemeColorEnum — when present, it is
 * wrapped in the theme's ANSI formatting before joining.
 *
 * Segment grouping: consecutive segments whose priorities differ by < 5
 * are spaced with whitespace; gaps >= 5 produce a "  |  " separator so
 * multi-colored token/cost blocks stay visually grouped.
 *
 * Truncation delegates to Symfony TUI's {@see AnsiUtils} for accurate
 * visible-width computation and ANSI-preserving truncation.
 */
final class FooterBarWidget implements TuiWidget
{
    private const int GROUP_SEPARATOR_GAP = 5;

    public function __construct(
        private readonly FooterDataProvider $dataProvider,
    ) {
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $segments = $this->dataProvider->getSegments();
        $statusEntries = $this->dataProvider->getStatusEntries();

        if ([] === $segments && [] === $statusEntries) {
            return [$context->theme->color(ThemeColorEnum::Footer, '  ◆ agent-core  |  type /help for commands')];
        }

        // ── Build segment structs with ANSI text and separator prefix ──
        // Each struct stores the rendered text and the separator that precedes it.
        // The first segment has an empty separator; subsequent segments use
        // either a pipe (group gap >= 5) or a single space.
        $structs = [];
        $prevPriority = null;

        foreach ($segments as $segment) {
            $text = $segment->text;
            if (null !== $segment->color) {
                $text = $context->theme->color($segment->color, $text);
            }

            $separator = '';
            if (null !== $prevPriority) {
                $gap = $segment->priority - $prevPriority;
                if ($gap >= self::GROUP_SEPARATOR_GAP) {
                    $separator = $context->theme->color(ThemeColorEnum::Dim, '  |  ');
                } else {
                    $separator = ' ';
                }
            }

            $structs[] = ['text' => $text, 'separator' => $separator];
            $prevPriority = $segment->priority;
        }

        // ── Build right-side status text ──
        $right = implode(' ', $statusEntries);
        $rightSep = '' !== $right ? '  ' : '';

        // ── Distribute segments across lines ──
        $available = max(10, $context->terminalWidth - 2);

        // No segments at all: render status on single line
        if ([] === $structs) {
            $combined = \sprintf('%s%s', $rightSep, $right);
            if (AnsiUtils::visibleWidth($combined) > $available) {
                $combined = AnsiUtils::truncateToWidth($combined, $available);
            }

            return [\sprintf('  %s', ltrim($combined))];
        }

        $lines = [];   // list of list of structs
        $currentLine = [];
        $currentWidth = 2; // leading "  " indent

        foreach ($structs as $struct) {
            $segWidth = AnsiUtils::visibleWidth($struct['text']);
            $separatorWidth = AnsiUtils::visibleWidth($struct['separator']);

            // First segment on a line has no separator
            $addedWidth = [] === $currentLine ? $segWidth : $separatorWidth + $segWidth;

            if ($currentWidth + $addedWidth <= $available) {
                $currentLine[] = $struct;
                $currentWidth += $addedWidth;
            } else {
                // Finish current line and start a new one with this segment
                if ([] !== $currentLine) {
                    $lines[] = $currentLine;
                }
                $currentLine = [$struct];
                $currentWidth = 2 + $segWidth;
            }
        }

        $lines[] = $currentLine;

        // ── Render each line ──
        $output = [];
        $lineCount = \count($lines);

        foreach ($lines as $lineIdx => $lineStructs) {
            $parts = [];
            foreach ($lineStructs as $struct) {
                // Only add separator if not the first element on this line
                if ([] !== $parts) {
                    $parts[] = $struct['separator'];
                }
                $parts[] = $struct['text'];
            }

            $lineContent = implode('', $parts);

            if ($lineIdx === $lineCount - 1) {
                // Last line: append right-side status and truncate if needed
                $combined = \sprintf('%s%s%s', $lineContent, $rightSep, $right);
                if (AnsiUtils::visibleWidth($combined) > $available) {
                    $rightVisible = AnsiUtils::visibleWidth($rightSep.$right);
                    $leftMax = max(0, $available - $rightVisible);
                    $lineContent = AnsiUtils::truncateToWidth($lineContent, $leftMax);
                    $combined = \sprintf('%s%s%s', $lineContent, $rightSep, $right);
                }
                $output[] = \sprintf('  %s', $combined);
            } else {
                $output[] = \sprintf('  %s', $lineContent);
            }
        }

        return $output;
    }
}
