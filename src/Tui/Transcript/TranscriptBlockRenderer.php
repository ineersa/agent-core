<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Tui\Ansi\TextWrapper;

/**
 * Translates a single {@see TranscriptBlock} into ANSI-styled, word-wrapped output lines.
 *
 * This is a pure renderer: it has no state, no layout opinions, and does not
 * implement {@see TuiWidget}. Widgets compose it for the display loop.
 *
 * Designed for RTVS-06 plain rendering: role prefixes, semantic theme colors,
 * and ANSI-safe wrapping. Rich markdown, interactive forms, and tool widgets
 * are deferred to later phases.
 */
final readonly class TranscriptBlockRenderer
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentResultRenderer = new SubagentResultRenderer(),
    ) {
    }

    /**
     * Render a single transcript block into styled, wrapped output lines.
     *
     * @param TuiRenderContext $context Terminal width and active theme
     *
     * @return list<string> One or more display lines
     */
    public function renderBlock(TranscriptBlock $block, TuiRenderContext $context): array
    {
        if ($this->subagentResultRenderer->supports($block)) {
            return $this->subagentResultRenderer->render($block, $context);
        }

        $prefix = $this->prefixFor($block);
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? '...' : '';

        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        $width = max($context->terminalWidth, 1);

        $lines = TextWrapper::wrapTextWithAnsi($line, $width);

        return array_map(
            static fn (string $line): string => $context->theme->color($color, $line),
            $lines,
        );
    }

    /* ───────── Prefix / color / text helpers ───────── */

    private function prefixFor(TranscriptBlock $block): string
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

    /**
     * Produce the visible text for a block, falling back to metadata
     * when the text field is empty.
     */
    private function displayTextFor(TranscriptBlock $block): string
    {
        if ('' !== $block->text) {
            return $block->text;
        }

        // Fall back to a label derived from metadata — useful for
        // tool-call placeholders and streaming blocks that haven't
        // accumulated visible text yet.
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

    private function labelOr(TranscriptBlock $block, string $metaKey, string $default): string
    {
        $value = $block->meta[$metaKey] ?? '';

        return \is_string($value) && '' !== $value ? $value : $default;
    }
}
