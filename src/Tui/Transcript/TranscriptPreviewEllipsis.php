<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Style;

/**
 * Styles collapsed-preview indicators (e.g. "… 113 more lines") as secondary italic text.
 *
 * Visible characters stay unchanged; only ANSI attributes are applied.
 */
final class TranscriptPreviewEllipsis
{
    /**
     * Private constructor — this class is not instantiable.
     */
    private function __construct()
    {
    }

    public static function style(TuiTheme $theme, string $ellipsis, ThemeColorEnum $color = ThemeColorEnum::Muted): string
    {
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, italic: true)
            : new Style(italic: true);

        return $style->apply($ellipsis);
    }
}
