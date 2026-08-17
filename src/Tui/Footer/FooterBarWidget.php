<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

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
 * visible-width computation and ANSI-preserving truncation. Every
 * returned line is truncated to the renderer column width, so the
 * widget never overflows the terminal at any width.
 *
 * Keyed status panel rows are intentionally not rendered here; they live
 * only in the status panel via ChatScreen::setStatus().
 */
final class FooterBarWidget extends AbstractWidget
{
    private const int GROUP_SEPARATOR_GAP = 5;

    public function __construct(
        private readonly TuiTheme $theme,
        private readonly FooterDataProvider $dataProvider,
    ) {
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        $segments = $this->dataProvider->getSegments();

        if ([] === $segments) {
            return [AnsiUtils::truncateToWidth(
                $this->theme->color(ThemeColorEnum::Footer, '  ◆ agent-core  |  type /help for commands'),
                $context->getColumns(),
            )];
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
                $text = $this->theme->color($segment->color, $text);
            }

            $separator = '';
            if (null !== $prevPriority) {
                $gap = $segment->priority - $prevPriority;
                if ($gap >= self::GROUP_SEPARATOR_GAP) {
                    $separator = $this->theme->color(ThemeColorEnum::Dim, '  |  ');
                } else {
                    $separator = ' ';
                }
            }

            $structs[] = ['text' => $text, 'separator' => $separator];
            $prevPriority = $segment->priority;
        }

        // ── Distribute segments across lines ──
        $available = max(10, $context->getColumns() - 2);

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

                if ($segWidth > $available) {
                    $currentLine[0]['text'] = AnsiUtils::truncateToWidth($struct['text'], $available);
                    $currentWidth = 2 + $available;
                }
            }
        }

        $lines[] = $currentLine;

        // ── Render each line ──
        $output = [];

        foreach ($lines as $lineStructs) {
            $parts = [];
            foreach ($lineStructs as $struct) {
                // Only add separator if not the first element on this line
                if ([] !== $parts) {
                    $parts[] = $struct['separator'];
                }
                $parts[] = $struct['text'];
            }

            $lineContent = implode('', $parts);
            if (AnsiUtils::visibleWidth($lineContent) > $available) {
                $lineContent = AnsiUtils::truncateToWidth($lineContent, $available);
            }
            // Outer width guarantee: the packing budget max(10, columns - 2)
            // can exceed the real column count at pathological widths, so the
            // final line is truncated to the renderer width (the same contract
            // the old LiveTextWidget(truncate: true) bridge enforced).
            $output[] = AnsiUtils::truncateToWidth(\sprintf('  %s', $lineContent), $context->getColumns());
        }

        return $output;
    }
}
