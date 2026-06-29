<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * POC: tab bar widget rendered above the header in ChatScreen.
 *
 * Renders a single-line horizontal bar showing tab labels.
 * Active tab is highlighted with the accent theme colour;
 * inactive tabs are muted.
 *
 * This is POC/prototype code and will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final class TabBarWidget implements TuiWidget
{
    public function __construct(
        private readonly TabService $tabService,
    ) {
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $count = $this->tabService->count();

        if (0 === $count) {
            return [];
        }

        $width = $context->terminalWidth;
        $activeIdx = $this->tabService->activeIndex();

        // Build label segments like:  [1] Parent  [2] Child  [3] Subagent
        $segments = [];
        foreach ($this->tabService->tabs() as $i => $tab) {
            $displayIdx = $i + 1;
            $isActive = $i === $activeIdx;

            $prefix = $isActive ? '[' : ' ';
            $suffix = $isActive ? ']' : ' ';
            $label = \sprintf('%s%d%s%s', $prefix, $displayIdx, $suffix, $tab->label);

            // Add gap between tabs (except after the last one)
            if ($i < $count - 1) {
                $label .= '  ';
            }

            $segments[$i] = $label;
        }

        // Join segments and style
        // Build the full line
        $line = '';
        foreach ($segments as $i => $segment) {
            if ($i === $activeIdx) {
                $line .= $context->theme->color(ThemeColorEnum::Accent, $segment);
            } else {
                $line .= $context->theme->color(ThemeColorEnum::Muted, $segment);
            }
        }

        // Pad to full width
        // Count visible width, pad with spaces
        $visibleLen = mb_strlen($segment ?? '');
        $segment = $line;
        // Actually let me recalculate
        $plainLen = 0;
        foreach ($this->tabService->tabs() as $i => $tab) {
            $displayIdx = $i + 1;
            $isActive = $i === $activeIdx;
            $prefix = $isActive ? '[' : ' ';
            $suffix = $isActive ? ']' : ' ';
            $labelLen = \strlen(\sprintf('%s%d%s%s', $prefix, $displayIdx, $suffix, $tab->label));
            $plainLen += $labelLen;
            if ($i < $count - 1) {
                $plainLen += 2;
            }
        }

        $padding = max(0, $width - $plainLen);

        // Redo with padding
        $resultSegments = [];
        foreach ($this->tabService->tabs() as $i => $tab) {
            $displayIdx = $i + 1;
            $isActive = $i === $activeIdx;

            $prefix = $isActive ? '[' : ' ';
            $suffix = $isActive ? ']' : ' ';
            $label = \sprintf('%s%d%s%s', $prefix, $displayIdx, $suffix, $tab->label);

            if ($i < $count - 1) {
                $label .= '  ';
            } elseif ($i === $count - 1) {
                // Add right padding after the last tab
                $label .= str_repeat(' ', $padding);
            }

            if ($isActive) {
                $resultSegments[] = $context->theme->color(ThemeColorEnum::Accent, $label);
            } else {
                $resultSegments[] = $context->theme->color(ThemeColorEnum::Muted, $label);
            }
        }

        return [implode('', $resultSegments)];
    }
}
