<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Default footer bar widget.
 *
 * Renders a compact single line of segments from the FooterDataProvider.
 * Each segment may carry an optional ThemeColor — when present, it is
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
            return [$context->theme->color(ThemeColor::Footer, '  ◆ agent-core  |  type /help for commands')];
        }

        // Build left-side parts with per-segment coloring and smart separators.
        $renderedParts = [];
        $prevPriority = null;

        foreach ($segments as $segment) {
            $text = $segment->text;
            if (null !== $segment->color) {
                $text = $context->theme->color($segment->color, $text);
            }

            // Insert separator before this segment (skip first)
            if (null !== $prevPriority) {
                $gap = $segment->priority - $prevPriority;
                if ($gap >= self::GROUP_SEPARATOR_GAP) {
                    $renderedParts[] = $context->theme->color(ThemeColor::Dim, '  |  ');
                } else {
                    $renderedParts[] = ' ';
                }
            }

            $renderedParts[] = $text;
            $prevPriority = $segment->priority;
        }

        $left = implode('', $renderedParts);

        // Build right-side status text from entries
        $statusParts = [];
        foreach ($statusEntries as $text) {
            $statusParts[] = $text;
        }

        $right = implode(' ', $statusParts);
        $separator = ('' !== $right) ? '  ' : '';

        // ANSI-aware truncation of the left part if combined exceeds available width
        $combined = \sprintf('%s%s%s', $left, $separator, $right);
        $available = max(10, $context->terminalWidth - 2);

        if (AnsiUtils::visibleWidth($combined) > $available) {
            $rightVisible = AnsiUtils::visibleWidth($separator.$right);
            $leftMax = max(0, $available - $rightVisible);
            $left = AnsiUtils::truncateToWidth($left, $leftMax);
            $combined = \sprintf('%s%s%s', $left, $separator, $right);
        }

        return [\sprintf('  %s', $combined)];
    }
}
