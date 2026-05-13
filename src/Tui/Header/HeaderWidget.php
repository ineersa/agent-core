<?php

declare(strict_types=1);

namespace Ineersa\Tui\Header;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Default header widget.
 *
 * Displays the application name with a simple ASCII separator.
 */
final class HeaderWidget implements TuiWidget
{
    public function __construct(
        private readonly string $title = 'Agent Core',
    ) {
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $title = \sprintf('  ◆ %s', $this->title);

        return [$context->theme->color(ThemeColor::Header, $title)];
    }
}
