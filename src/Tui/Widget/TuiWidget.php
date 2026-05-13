<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

/**
 * Lightweight renderable widget abstraction.
 *
 * This is deliberately independent of Symfony\Component\Tui\AbstractWidget.
 * It gives us a stable rendering contract that does not overcommit to
 * Symfony TUI's experimental API.
 *
 * Each widget renders to a list of plain text/ANSI lines. The layout
 * compositor (ChatLayout) merges widget output in display order.
 *
 * @see ChatLayout
 * @see TuiRenderContext
 */
interface TuiWidget
{
    /**
     * Render the widget to a list of output lines.
     *
     * @param TuiRenderContext $context Terminal dimensions and rendering metadata
     *
     * @return list<string> 0 or more lines of rendered output
     */
    public function render(TuiRenderContext $context): array;
}
