<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Full-width muted turn separator that tracks live terminal columns.
 */
final class TurnSeparatorWidget extends AbstractWidget
{
    public function __construct(
        private readonly TuiTheme $theme,
    ) {
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $width = max($context->getColumns(), 1);

        return [$this->theme->muted(str_repeat(TranscriptGlyphs::TURN_SEPARATOR_CHAR, $width))];
    }
}
