<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Leaf stand-in for a visual node truncated to the rendered-row budget.
 *
 * Holds already-wrapped terminal rows captured while the source widget was
 * temporarily attached under the live WidgetContext, so theme stylesheets apply
 * and the mounted tree never retains the oversized source widget.
 */
final class TranscriptClippedRowsWidget extends AbstractWidget
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        private readonly array $lines,
    ) {
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        return $this->lines;
    }
}
