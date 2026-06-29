<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Builds Symfony TUI widget trees from {@see TranscriptBlock} DTOs.
 *
 * This factory centralises all block-kind-specific rendering logic
 * (glyphs, colors, display-text fallbacks, severity handling) that
 * was previously spread across several flat renderers. It produces
 * Symfony {@see TextWidget} instances that carry ANSI-coloured text
 * so the Symfony {@see Renderer} / {@see TextWidget} wrapping pipeline
 * produces the correct terminal output.
 *
 * Each block renders as a single TextWidget whose text is the
 * traditional charismatic flat-transcript line:
 *   "  <glyph> <display-text><streaming-suffix>"
 * wrapped in the block-kind-specific theme colour.
 *
 * Structured subagent result blocks are delegated to
 * {@see SubagentResultRenderer::buildContent()}.
 */
final readonly class TranscriptBlockWidgetFactory
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer = new SubagentResultRenderer(),
    ) {
    }

    /**
     * Build a root ContainerWidget containing one TextWidget per block.
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
     * Build a single TextWidget for one transcript block.
     */
    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        if ($this->subagentRenderer->supports($block)) {
            return new TextWidget($this->subagentRenderer->buildContent($block, $theme));
        }

        $prefix = $this->prefixFor($block);
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? '...' : '';

        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        return new TextWidget($theme->color($color, $line));
    }

    /* ───────── Glyph prefixes ───────── */

    public function prefixFor(TranscriptBlock $block): string
    {
        return match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => '  ❯',
            TranscriptBlockKindEnum::AssistantMessage => '  ◇',
            TranscriptBlockKindEnum::AssistantThinking => '  ⋯',
            TranscriptBlockKindEnum::ToolCall,
            TranscriptBlockKindEnum::ToolResult => '  ●',
            TranscriptBlockKindEnum::Progress => '  ⏳',
            TranscriptBlockKindEnum::Question => '  ?',
            TranscriptBlockKindEnum::Approval => '  🔐',
            TranscriptBlockKindEnum::Cancelled => '  ✕',
            TranscriptBlockKindEnum::Error => '  ✕',
            TranscriptBlockKindEnum::System => $this->severityPrefix($block),
        };
    }

    /* ───────── Theme colors ───────── */

    public function colorFor(TranscriptBlock $block): ThemeColorEnum
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

    public function displayTextFor(TranscriptBlock $block): string
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
            'info' => '  ℹ',
            'warning' => '  ⚠',
            'error' => '  ✘',
            default => '  ·',
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

    private function labelOr(TranscriptBlock $block, string $metaKey, string $default): string
    {
        $value = $block->meta[$metaKey] ?? '';

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
