<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Builds Symfony TUI stylesheets for themed transcript widgets from a Hatfield theme palette.
 *
 * Mounted widgets resolve these selectors through live WidgetContext.
 * Empty palette values are omitted so Symfony defaults remain for unset tokens.
 *
 * Link styles keep underline (Symfony default) while applying the theme color.
 */
final class ThemeStyleSheetFactory
{
    public function createMarkdown(ThemePalette $palette): StyleSheet
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

    public function createHotkeyTable(ThemePalette $palette): StyleSheet
    {
        $rules = [];

        $this->addRule($rules, HotkeyTableWidget::class.'::heading', $palette, ThemeColorEnum::Accent);
        $this->addRule($rules, HotkeyTableWidget::class.'::context', $palette, ThemeColorEnum::Accent);
        $this->addRule($rules, HotkeyTableWidget::class.'::border', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, HotkeyTableWidget::class.'::header', $palette, ThemeColorEnum::Accent);
        $this->addRule($rules, HotkeyTableWidget::class.'::key', $palette, ThemeColorEnum::Success);
        $this->addRule($rules, HotkeyTableWidget::class.'::description', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, HotkeyTableWidget::class.'::footer', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, HotkeyTableWidget::class.'::empty', $palette, ThemeColorEnum::Muted);

        return new StyleSheet($rules);
    }

    public function createSubagentProgressCard(ThemePalette $palette): StyleSheet
    {
        $rules = [];

        $this->addRule($rules, SubagentProgressCardWidget::class.'::border', $palette, ThemeColorEnum::BorderAccent);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::border-failed', $palette, ThemeColorEnum::Error);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::border-cancelled', $palette, ThemeColorEnum::BorderMuted);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::border-waiting', $palette, ThemeColorEnum::Warning);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::header-running', $palette, ThemeColorEnum::Accent);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::header-completed', $palette, ThemeColorEnum::Success);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::header-failed', $palette, ThemeColorEnum::Error);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::header-cancelled', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::header-waiting', $palette, ThemeColorEnum::Warning);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::meta', $palette, ThemeColorEnum::ToolTitle);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::tool', $palette, ThemeColorEnum::ToolOutput);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::body', $palette, ThemeColorEnum::ToolOutput);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::muted', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::ctx-label', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::ctx-ok', $palette, ThemeColorEnum::Success);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::ctx-warn', $palette, ThemeColorEnum::Warning);
        $this->addRule($rules, SubagentProgressCardWidget::class.'::ctx-error', $palette, ThemeColorEnum::Error);

        return new StyleSheet($rules);
    }

    /**
     * Styles scoped to the ask_human/native question SelectListWidget class only.
     *
     * Uses class selectors so session/model/history pickers keep Symfony defaults.
     */
    public function createQuestionChoiceList(ThemePalette $palette): StyleSheet
    {
        $rules = [];

        $this->addRule($rules, '.question-choice-list::selected', $palette, ThemeColorEnum::Accent, bold: true);
        $this->addRule($rules, '.question-choice-list::label', $palette, ThemeColorEnum::Text);
        $this->addRule($rules, '.question-choice-list::description', $palette, ThemeColorEnum::Muted);
        $this->addRule($rules, '.question-choice-list::scroll-info', $palette, ThemeColorEnum::Muted);

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
