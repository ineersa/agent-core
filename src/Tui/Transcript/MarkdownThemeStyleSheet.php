<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\DefaultStyleSheet;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Builds and applies a Symfony TUI {@see StyleSheet} mapping active theme
 * markdown tokens to {@see MarkdownWidget} sub-element selectors.
 *
 * The {@see MarkdownWidget} renders inline elements (headings, links, code,
 * quotes, lists, etc.) by calling {@see AbstractWidget::resolveElement()},
 * which looks up rules in the widget stylesheet. When the widget is not
 * attached to a full Symfony TUI {@see WidgetContext} (our use case), it
 * falls back to a static shared default stylesheet. This helper builds a
 * theme-aware stylesheet and installs it as that fallback.
 *
 * The result is that all markdown elements in the transcript get styled
 * with the active Hatfield theme's markdown token colors instead of
 * Symfony TUI's built-in defaults.
 */
final readonly class MarkdownThemeStyleSheet
{
    /**
     * Build a {@see StyleSheet} with markdown sub-element rules populated
     * from the active theme palette.
     *
     * Each supported {@see MarkdownWidget} sub-element selector gets a
     * {@see Style} derived from the corresponding {@see ThemeColorEnum}
     * markdown token. Empty palette entries (code-block, link-url, etc.)
     * produce a Style with no color override, so the default terminal
     * foreground or inherited style is used.
     *
     * Default decorative attributes (bold for headings, underline for
     * links, italic for quotes) are preserved alongside the theme color.
     */
    public static function build(TuiTheme $theme): StyleSheet
    {
        $palette = $theme->getPalette();

        return new StyleSheet([
            MarkdownWidget::class.'::heading' => self::style($palette, ThemeColorEnum::MarkdownHeading, bold: true),
            MarkdownWidget::class.'::link' => self::style($palette, ThemeColorEnum::MarkdownLink, underline: true),
            MarkdownWidget::class.'::link-url' => self::style($palette, ThemeColorEnum::MarkdownLinkUrl),
            MarkdownWidget::class.'::code' => self::style($palette, ThemeColorEnum::MarkdownCode),
            MarkdownWidget::class.'::code-block-border' => self::style($palette, ThemeColorEnum::MarkdownCodeBlockBorder),
            MarkdownWidget::class.'::quote' => self::style($palette, ThemeColorEnum::MarkdownQuote, italic: true),
            MarkdownWidget::class.'::quote-border' => self::style($palette, ThemeColorEnum::MarkdownQuoteBorder),
            MarkdownWidget::class.'::hr' => self::style($palette, ThemeColorEnum::MarkdownHr),
            MarkdownWidget::class.'::list-bullet' => self::style($palette, ThemeColorEnum::MarkdownListBullet),
        ]);
    }

    /**
     * Apply the theme's markdown element colours to the shared
     * {@see AbstractWidget} static fallback stylesheet.
     *
     * Because {@see MarkdownWidget} instances in our transcript pipeline
     * are rendered through the standalone {@see Renderer} (not attached
     * to a full {@see Tui} tree), {@see AbstractWidget::resolveElement()}
     * uses the static fallback. This method sets that fallback, re-applying
     * only when the theme name changes (cheap identity check).
     *
     * Safe to call multiple times from multiple render cycles — the
     * static detection prevents redundant StyleSheet construction and
     * reflection writes on the same theme.
     */
    public static function apply(TuiTheme $theme): void
    {
        // Static cache: skip if this theme was already applied.
        // Uses theme name as cheap identity key; theme-switching at
        // runtime (future feature) would need to invalidate this.
        static $lastThemeName = '';
        if ($lastThemeName === $theme->name()) {
            return;
        }
        $lastThemeName = $theme->name();

        $defaults = DefaultStyleSheet::create();
        $themeSheet = self::build($theme);

        // Merge: defaults (lowest priority), then theme (overrides matching selectors)
        $merged = clone $defaults;
        $merged->merge($themeSheet);

        // PHP 8.1+ allows reading/writing private properties through
        // ReflectionProperty without setAccessible().
        (new \ReflectionProperty(AbstractWidget::class, 'defaultStyleSheet'))->setValue(null, $merged);
    }

    /**
     * Build a Style for a markdown sub-element selector, with optional
     * default decorations (bold / underline / italic) that combine
     * with the theme colour.
     */
    private static function style(ThemePalette $palette, ThemeColorEnum $token, bool $bold = false, bool $underline = false, bool $italic = false): Style
    {
        $spec = $palette->get($token);
        if ('' !== $spec) {
            return new Style(color: $spec, bold: $bold, underline: $underline, italic: $italic);
        }

        // Token maps to empty string — colour comes from inherited/fallback
        return new Style(bold: $bold, underline: $underline, italic: $italic);
    }
}
