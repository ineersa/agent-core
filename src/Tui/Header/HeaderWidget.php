<?php

declare(strict_types=1);

namespace Ineersa\Tui\Header;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Default header widget with the Hatfield ASCII logo.
 *
 * Renders the logo as ANSI-styled lines and wraps them to the live render
 * columns (same Symfony {@see TextWrapper} primitive the previous
 * LiveTextWidget adapter used), so narrow terminals reflow instead of
 * overflowing.
 */
final class HeaderWidget extends AbstractWidget
{
    private const HATFIELD_LOGO = <<<'ASCII'
██╗ ██╗      ██╗  ██╗ █████╗ ████████╗███████╗██╗███████╗██╗     ██████╗     ██╗██╗██╗
╚██╗╚██╗     ██║  ██║██╔══██╗╚══██╔══╝██╔════╝██║██╔════╝██║     ██╔══██╗    ██║██║██║
 ╚██╗╚██╗    ███████║███████║   ██║   █████╗  ██║█████╗  ██║     ██║  ██║    ██║██║██║
 ██╔╝██╔╝    ██╔══██║██╔══██║   ██║   ██╔══╝  ██║██╔══╝  ██║     ██║  ██║    ╚═╝╚═╝╚═╝
██╔╝██╔╝     ██║  ██║██║  ██║   ██║   ██║     ██║███████╗███████╗██████╔╝    ██╗██╗██╗
╚═╝ ╚═╝      ╚═╝  ╚═╝╚═╝  ╚═╝   ╚═╝   ╚═╝     ╚═╝╚══════╝╚══════╝╚═════╝     ╚═╝╚═╝╚═╝
ASCII;

    public function __construct(
        private readonly TuiTheme $theme,
        private readonly string $text = self::HATFIELD_LOGO,
    ) {
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        $lines = explode("\n", $this->text);
        $result = [];

        foreach ($lines as $line) {
            $result[] = $this->theme->color(ThemeColorEnum::Header, '  '.rtrim($line));
        }

        // Same wrap primitive LiveTextWidget used, so output stays identical
        // at any width (long logo lines hard-break on narrow terminals).
        return TextWrapper::wrapTextWithAnsi(implode("\n", $result), $context->getColumns());
    }
}
