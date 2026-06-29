<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * POC: tab bar widget rendered above the header in ChatScreen.
 *
 * Renders a single-line horizontal bar showing tab labels.
 * Active tab is highlighted with the accent theme colour;
 * inactive tabs are muted.
 *
 * Does NOT depend on TuiRuntime\TabService to preserve the deptrac
 * boundary. Instead accepts a Closure returning TabBarData at render
 * time, so the widget stays in the TuiWidget layer.
 *
 * This is POC/prototype code and will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final class TabBarWidget implements TuiWidget
{
    /** @var \Closure(): TabBarData */
    private readonly \Closure $dataProvider;

    /**
     * @param \Closure(): TabBarData $dataProvider called on each render
     *                                             to get the current tab
     *                                             display data
     */
    public function __construct(\Closure $dataProvider)
    {
        $this->dataProvider = $dataProvider;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $data = ($this->dataProvider)();
        $count = $data->count();

        if (0 === $count) {
            return [];
        }

        $width = $context->terminalWidth;
        $activeIdx = $data->activeIndex;

        // Count visible width, pad with spaces
        $plainLen = 0;
        foreach ($data->tabs as $i => $tab) {
            $displayIdx = $i + 1;
            $isActive = $i === $activeIdx;
            $prefix = $isActive ? '[' : ' ';
            $suffix = $isActive ? ']' : ' ';
            $labelLen = \strlen(\sprintf('%s%d%s%s', $prefix, $displayIdx, $suffix, $tab['label']));
            $plainLen += $labelLen;
            if ($i < $count - 1) {
                $plainLen += 2;
            }
        }

        $padding = max(0, $width - $plainLen);

        // Build result segments with styling and padding
        $resultSegments = [];
        foreach ($data->tabs as $i => $tab) {
            $displayIdx = $i + 1;
            $isActive = $i === $activeIdx;

            $prefix = $isActive ? '[' : ' ';
            $suffix = $isActive ? ']' : ' ';
            $label = \sprintf('%s%d%s%s', $prefix, $displayIdx, $suffix, $tab['label']);

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
