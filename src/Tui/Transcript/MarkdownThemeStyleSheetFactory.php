<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Builds a Symfony TUI stylesheet for MarkdownWidget sub-elements from a Hatfield theme palette.
 *
 * Mounted MarkdownWidget instances resolve these selectors through live WidgetContext.
 * Empty palette values are omitted so Symfony defaults remain for unset tokens.
 *
 * Link styles keep underline (Symfony default) while applying the theme color.
 */
final class MarkdownThemeStyleSheetFactory
{
    public function create(TuiTheme $theme): StyleSheet
    {
        return $this->createFromPalette($theme->getPalette());
    }

    public function createFromPalette(ThemePalette $palette): StyleSheet
    {
        $rules = [];

        $this->addRule($rules, MarkdownWidget::class.'::heading', $palette, ThemeColorEnum::MarkdownHeading, bold: true);
        $this->addRule($rules, MarkdownWidget::class.'::link', $palette, ThemeColorEnum::MarkdownLink, underline: true);
        $this->addRule($rules, MarkdownWidget::class.'::link-url', $palette, ThemeColorEnum::MarkdownLinkUrl);
        $this->addRule($rules, MarkdownWidget::class.'::code', $palette, ThemeColorEnum::MarkdownCode);
        $this->addRule($rules, MarkdownWidget::class.'::code-block-border', $palette, ThemeColorEnum::MarkdownCodeBlockBorder);
        $this->addRule($rules, MarkdownWidget::class.'::quote', $palette, ThemeColorEnum::MarkdownQuote, italic: true);
        $this->addRule($rules, MarkdownWidget::class.'::quote-border', $palette, ThemeColorEnum::MarkdownQuoteBorder);
        $this->addRule($rules, MarkdownWidget::class.'::hr', $palette, ThemeColorEnum::MarkdownHr);
        $this->addRule($rules, MarkdownWidget::class.'::list-bullet', $palette, ThemeColorEnum::MarkdownListBullet);

        return new StyleSheet($rules);
    }

    /**
     * @param array<string, Style> $rules
     */
    private function addRule(
        array &$rules,
        string $selector,
        ThemePalette $palette,
        ThemeColorEnum $token,
        bool $bold = false,
        bool $italic = false,
        bool $underline = false,
    ): void {
        $spec = $palette->get($token);
        if ('' === $spec) {
            return;
        }

        $rules[$selector] = new Style(
            color: $spec,
            bold: $bold ? true : null,
            italic: $italic ? true : null,
            underline: $underline ? true : null,
        );
    }
}
