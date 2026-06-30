<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Builds Symfony TUI widget trees from {@see TranscriptBlock} DTOs.
 *
 * This factory centralises all block-kind-specific rendering logic
 * (glyphs, colors, display-text fallbacks, severity handling) that
 * was previously spread across several flat renderers.
 *
 * User messages, assistant messages, and visible thinking blocks are
 * rendered through Symfony {@see MarkdownWidget} for rich markdown
 * formatting. Hidden thinking (when {@see TranscriptDisplayConfig}
 * {@code thinkingVisible === false}) renders the compact placeholder.
 * All other block kinds (tool calls, errors, system, etc.) produce
 * Symfony {@see TextWidget} instances with ANSI-coloured text.
 *
 * Structured subagent result blocks are delegated to
 * {@see SubagentResultRenderer::buildContent()}.
 */
final readonly class TranscriptBlockWidgetFactory
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer = new SubagentResultRenderer(),
        private readonly TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
    ) {
    }

    /**
     * Build a root ContainerWidget containing one widget per block.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function buildRoot(array $blocks, TuiTheme $theme): ContainerWidget
    {
        $root = new ContainerWidget();
        foreach ($blocks as $block) {
            $root->add($this->buildWidget($block, $theme));
        }

        return $root;
    }

    /**
     * Build a single widget for one transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        // Structured subagent result blocks
        if ($this->subagentRenderer->supports($block)) {
            return new TextWidget($this->subagentRenderer->buildContent($block, $theme));
        }

        // Hidden thinking: always render compact placeholder, never raw content.
        // This uses TranscriptDisplayConfig only, NOT TranscriptBlock::collapsed.
        if ($this->isThinkingBlock($block) && !$this->displayConfig->thinkingVisible) {
            $prefix = TranscriptGlyphs::GLYPH_ASSISTANT_THINKING;
            $line = \sprintf('%s Thinking', $prefix);

            return new TextWidget($theme->color(ThemeColorEnum::ThinkingText, $line));
        }

        // UserMessage, AssistantMessage, visible thinking → MarkdownWidget
        if ($this->isMarkdownBlock($block)) {
            return $this->buildMarkdownWidget($block, $theme);
        }

        // All other kinds → existing TextWidget path
        $prefix = $this->prefixFor($block);
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        return new TextWidget($theme->color($color, $line));
    }

    /* ───────── Glyph prefixes ─────────
     *
     * These methods are private — {@see TranscriptGlyphs} constants are the public API
     * for tests and rendering assertions. */

    private function prefixFor(TranscriptBlock $block): string
    {
        return match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => TranscriptGlyphs::GLYPH_USER_MESSAGE,
            TranscriptBlockKindEnum::AssistantMessage => TranscriptGlyphs::GLYPH_ASSISTANT_MESSAGE,
            TranscriptBlockKindEnum::AssistantThinking => TranscriptGlyphs::GLYPH_ASSISTANT_THINKING,
            TranscriptBlockKindEnum::ToolCall,
            TranscriptBlockKindEnum::ToolResult => TranscriptGlyphs::GLYPH_TOOL,
            TranscriptBlockKindEnum::Progress => TranscriptGlyphs::GLYPH_PROGRESS,
            TranscriptBlockKindEnum::Question => TranscriptGlyphs::GLYPH_QUESTION,
            TranscriptBlockKindEnum::Approval => TranscriptGlyphs::GLYPH_APPROVAL,
            TranscriptBlockKindEnum::Cancelled => TranscriptGlyphs::GLYPH_CANCELLED,
            TranscriptBlockKindEnum::Error => TranscriptGlyphs::GLYPH_ERROR,
            TranscriptBlockKindEnum::System => $this->severityPrefix($block),
        };
    }

    /* ───────── Theme colors ───────── */

    private function colorFor(TranscriptBlock $block): ThemeColorEnum
    {
        return match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => ThemeColorEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage => ThemeColorEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking => ThemeColorEnum::ThinkingText,
            TranscriptBlockKindEnum::ToolCall => ThemeColorEnum::Tool,
            TranscriptBlockKindEnum::ToolResult => ThemeColorEnum::ToolOutput,
            TranscriptBlockKindEnum::Progress,
            TranscriptBlockKindEnum::Cancelled => ThemeColorEnum::Muted,
            TranscriptBlockKindEnum::Question => ThemeColorEnum::Accent,
            TranscriptBlockKindEnum::Approval => ThemeColorEnum::Warning,
            TranscriptBlockKindEnum::Error => ThemeColorEnum::Error,
            TranscriptBlockKindEnum::System => $this->severityColor($block),
        };
    }

    /* ───────── Display text (fallback for empty text) ───────── */

    private function displayTextFor(TranscriptBlock $block): string
    {
        if ('' !== $block->text) {
            return $block->text;
        }

        return match ($block->kind) {
            TranscriptBlockKindEnum::ToolCall => $this->labelOr($block, 'tool_name', 'Tool call'),
            TranscriptBlockKindEnum::ToolResult => $this->labelOr($block, 'tool_name', 'Tool result'),
            TranscriptBlockKindEnum::AssistantMessage => '[assistant]',
            TranscriptBlockKindEnum::AssistantThinking => '[thinking]',
            TranscriptBlockKindEnum::Question => '[question]',
            TranscriptBlockKindEnum::Approval => '[approval]',
            TranscriptBlockKindEnum::Cancelled => '[cancelled]',
            TranscriptBlockKindEnum::Error => '[error]',
            TranscriptBlockKindEnum::Progress => '[progress]',
            default => '',
        };
    }

    /* ───────── Severity helpers (System block) ───────── */

    private function severityPrefix(TranscriptBlock $block): string
    {
        $severity = \is_string($block->meta['severity'] ?? null)
            ? $block->meta['severity']
            : null;

        return match ($severity) {
            'info' => TranscriptGlyphs::GLYPH_SYSTEM_INFO,
            'warning' => TranscriptGlyphs::GLYPH_SYSTEM_WARNING,
            'error' => TranscriptGlyphs::GLYPH_SYSTEM_ERROR,
            default => TranscriptGlyphs::GLYPH_SYSTEM_DEFAULT,
        };
    }

    private function severityColor(TranscriptBlock $block): ThemeColorEnum
    {
        $severity = \is_string($block->meta['severity'] ?? null)
            ? $block->meta['severity']
            : null;

        return match ($severity) {
            'warning' => ThemeColorEnum::Warning,
            'error' => ThemeColorEnum::Error,
            default => ThemeColorEnum::SystemMessage,
        };
    }

    /* ───────── Markdown widget builder ───────── */

    /**
     * Build a MarkdownWidget for user, assistant, or visible thinking blocks.
     *
     * Prepends the glyph (without leading indent — achieved via left padding
     * in the widget Style) and streaming suffix to the raw text, so the
     * existing charismatic glyph/prefix visual language is preserved.
     *
     * Left padding of 2 replaces the flat renderer's "  " prefix so that
     * the CommonMark parser does not strip meaningful leading whitespace.
     */
    private function buildMarkdownWidget(TranscriptBlock $block, TuiTheme $theme): MarkdownWidget
    {
        $prefix = trim($this->prefixFor($block));
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        // Prepend clean glyph prefix and append streaming suffix
        $text = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        $mdWidget = new MarkdownWidget($text);

        // Build a Style with the block-kind theme colour and 2-char left padding
        // (replaces the flat-renderer "  " indentation before the glyph)
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));

        // Apply thinking visual style (dim, italic) when configured
        if ($this->isThinkingBlock($block)) {
            $style = $this->applyThinkingStyle($style);
        }

        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    /**
     * Map the configured thinking style string to Style attributes.
     *
     * Supported: 'dim_italic' (default), 'dim', 'italic'.
     * Invalid/unknown values fall through to the base style unchanged.
     */
    private function applyThinkingStyle(Style $style): Style
    {
        return match ($this->displayConfig->thinkingStyle) {
            'dim_italic' => $style->withDim(true)->withItalic(true),
            'dim' => $style->withDim(true),
            'italic' => $style->withItalic(true),
            default => $style,
        };
    }

    private function isThinkingBlock(TranscriptBlock $block): bool
    {
        return TranscriptBlockKindEnum::AssistantThinking === $block->kind;
    }

    private function isMarkdownBlock(TranscriptBlock $block): bool
    {
        return \in_array($block->kind, [
            TranscriptBlockKindEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking,
        ], true);
    }

    private function labelOr(TranscriptBlock $block, string $metaKey, string $default): string
    {
        $value = $block->meta[$metaKey] ?? '';

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
