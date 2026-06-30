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
use Symfony\Component\Yaml\Yaml;

/**
 * Centralizes block-kind-specific rendering for the transcript widget tree.
 *
 * Responsibilities include glyphs, theme colors, fallback display text, system severity,
 * markdown/thinking paths, and compact tool cards.
 *
 * User / assistant / visible thinking → {@see MarkdownWidget}.
 * Hidden thinking → compact placeholder from {@see TranscriptDisplayConfig} only,
 * not {@see TranscriptBlock::$collapsed}.
 * {@see TranscriptBlockKindEnum::ToolCall} and normal {@see TranscriptBlockKindEnum::ToolResult}
 * → compact multi-line cards (YAML-like args with preview, preview-truncated result body).
 * Structured subagent tool results are delegated to {@see SubagentResultRenderer} before generic
 * ToolResult cards. All other kinds → {@see TextWidget} flat line.
 */
final readonly class TranscriptBlockWidgetFactory
{
    public function __construct(
        private readonly SubagentResultRenderer $subagentRenderer = new SubagentResultRenderer(),
        private readonly TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        private readonly TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        private readonly ToolArgumentsFormatter $toolArgumentsFormatter = new ToolArgumentsFormatter(),
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
        // Structured subagent result blocks stay on the dedicated renderer before generic ToolResult cards.
        if ($this->subagentRenderer->supports($block)) {
            return new TextWidget($this->subagentRenderer->buildContent($block, $theme));
        }

        // Hidden thinking: compact placeholder; uses TranscriptDisplayConfig only, NOT TranscriptBlock::collapsed.
        if ($this->isThinkingBlock($block) && !$this->displayConfig->thinkingVisible) {
            $line = \sprintf('%s Thinking', TranscriptGlyphs::GLYPH_ASSISTANT_THINKING);

            return new TextWidget($theme->color(ThemeColorEnum::ThinkingText, $line));
        }

        // UserMessage, AssistantMessage, visible thinking → MarkdownWidget.
        if ($this->isMarkdownBlock($block)) {
            return $this->buildMarkdownWidget($block, $theme);
        }

        // RENDER-04: ToolCall → compact card (glyph header, YAML-like args, arg preview).
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            return $this->buildToolCallWidget($block, $theme);
        }

        // RENDER-04: normal ToolResult → compact card (header, body preview unless error/cancel/timeout).
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            return $this->buildToolResultWidget($block, $theme);
        }

        // All remaining kinds → existing TextWidget path.
        $prefix = $this->prefixFor($block);
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $line = \sprintf('%s %s%s', $prefix, $displayText, $suffix);

        return new TextWidget($theme->color($color, $line));
    }

    private function buildToolCallWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        $header = $this->toolCallHeaderLabel($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $lines = [\sprintf('%s %s%s', TranscriptGlyphs::GLYPH_TOOL, $header, $suffix)];

        $arguments = $block->meta['arguments'] ?? null;
        if (\is_array($arguments) && [] !== $arguments) {
            $argLines = $this->toolArgumentsFormatter->formatLines($arguments);
            $preview = $this->applyLinePreview($argLines, fullRender: false);
            foreach ($preview['lines'] as $argLine) {
                $lines[] = '    '.$argLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.$preview['ellipsis'];
            }
        }

        $text = implode("\n", $lines);

        return new TextWidget($theme->color(ThemeColorEnum::ToolTitle, $text));
    }

    private function buildToolResultWidget(TranscriptBlock $block, TuiTheme $theme): TextWidget
    {
        $header = $this->toolResultHeaderLabel($block);
        $lines = [\sprintf('%s %s', TranscriptGlyphs::GLYPH_TOOL, $header)];

        $body = $this->toolResultBodyText($block);
        if ('' !== $body) {
            $bodyLines = explode("\n", $body);
            $preview = $this->applyToolResultPreview($bodyLines, $block);
            foreach ($preview['lines'] as $bodyLine) {
                $lines[] = '    '.$bodyLine;
            }
            if (null !== $preview['ellipsis']) {
                $lines[] = '    '.$preview['ellipsis'];
            }
        }

        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        if ('' !== $suffix) {
            $lines[0] .= $suffix;
        }

        $color = $this->toolResultIsFullRender($block) && $this->metaIsTruthy($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;

        return new TextWidget($theme->color($color, implode("\n", $lines)));
    }

    /**
     * @param list<string> $bodyLines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    private function applyToolResultPreview(array $bodyLines, TranscriptBlock $block): array
    {
        return $this->applyLinePreview($bodyLines, $this->toolResultIsFullRender($block));
    }

    /**
     * Shared line-budget preview for ToolCall argument lines and ToolResult body lines.
     *
     * When {@see TranscriptDisplayConfig::$toolResultPreviewLines} is <= 0, preview is disabled
     * (all lines returned). When {@see TranscriptDisplayState::$previewableBlocksExpanded} is true,
     * all lines are returned regardless of limit.
     *
     * @param list<string> $lines
     *
     * @return array{lines: list<string>, ellipsis: ?string}
     */
    private function applyLinePreview(array $lines, bool $fullRender): array
    {
        if ($fullRender) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        $limit = $this->displayConfig->toolResultPreviewLines;
        if ($limit <= 0 || \count($lines) <= $limit) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        if ($this->displayState->previewableBlocksExpanded) {
            return ['lines' => $lines, 'ellipsis' => null];
        }

        $remaining = \count($lines) - $limit;
        $ellipsis = \sprintf('… %d more line%s', $remaining, 1 === $remaining ? '' : 's');

        return [
            'lines' => \array_slice($lines, 0, $limit),
            'ellipsis' => $ellipsis,
        ];
    }

    /**
     * Error, cancelled, and timed_out tool results bypass preview so diagnostics are not hidden.
     *
     * Projection currently sets is_error for cancelled/timed_out as well; color still keys off is_error when full.
     */
    private function toolResultIsFullRender(TranscriptBlock $block): bool
    {
        return $this->metaIsTruthy($block->meta['is_error'] ?? false)
            || $this->metaIsTruthy($block->meta['cancelled'] ?? false)
            || $this->metaIsTruthy($block->meta['timed_out'] ?? false);
    }

    private function metaIsTruthy(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value;
    }

    private function toolCallHeaderLabel(TranscriptBlock $block): string
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName) {
            return $toolName;
        }

        if ('' !== $block->text) {
            return $block->text;
        }

        return 'Tool call';
    }

    private function toolResultHeaderLabel(TranscriptBlock $block): string
    {
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName) {
            return $toolName;
        }

        if ('' !== $block->text && !$this->looksLikeMultilineBody($block->text)) {
            return $block->text;
        }

        return 'Tool result';
    }

    private function looksLikeMultilineBody(string $text): bool
    {
        return str_contains($text, "\n");
    }

    private function toolResultBodyText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;
        if (\is_string($result) && '' !== $result) {
            return $result;
        }
        if (\is_scalar($result) && '' !== (string) $result) {
            return (string) $result;
        }
        if (\is_array($result) || \is_object($result)) {
            return trim(Yaml::dump($result, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        }

        $text = $block->text;
        $toolName = $block->meta['tool_name'] ?? null;
        if (\is_string($toolName) && '' !== $toolName && $text === $toolName) {
            return '';
        }
        if ('Tool result' === $text) {
            return '';
        }

        return $text;
    }

    // Glyph prefixes — TranscriptGlyphs constants are the public glyph contract for tests/assertions.
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

    // Theme colors per block kind (flat TextWidget path and markdown base color).
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

    // Display text when block->text is empty (meta fallbacks and kind placeholders).
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

    // System block severity → glyph prefix.
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

    // System block severity → theme color.
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
     * Markdown block: glyph prepended into markdown source, streaming suffix preserved.
     *
     * Left padding on the widget replaces the flat renderer's two leading spaces because
     * CommonMark strips leading whitespace from paragraph text.
     */
    private function buildMarkdownWidget(TranscriptBlock $block, TuiTheme $theme): MarkdownWidget
    {
        $prefix = trim($this->prefixFor($block));
        $color = $this->colorFor($block);
        $displayText = $this->displayTextFor($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';
        $text = \sprintf('%s %s%s', $prefix, $displayText, $suffix);
        $mdWidget = new MarkdownWidget($text);
        $colorSpec = $theme->getPalette()->get($color);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));

        if ($this->isThinkingBlock($block)) {
            $style = $this->applyThinkingStyle($style);
        }

        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    /**
     * Maps thinking.style config: dim_italic, dim, italic. Invalid values leave base style unchanged.
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
