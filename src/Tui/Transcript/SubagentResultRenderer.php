<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;

/**
 * Builds structured subagent tool-result content for the widget-tree renderer.
 *
 * This class provides content strings (not flat-rendered lines) for the
 * Symfony TUI widget-tree pipeline in {@see TranscriptBlockWidgetFactory}.
 * The old flat {@see render()} path has been removed — all rendering now
 * flows through {@see buildContent()} → TextWidget wrapping.
 *
 * Only subagent-related blocks ({@see supports()}) are handled here;
 * normal tool-call/result blocks go through {@see TranscriptBlockWidgetFactory::buildWidget()}.
 */
final readonly class SubagentResultRenderer
{
    public function __construct(
        private SubagentProgressDisplayFormatter $formatter = new SubagentProgressDisplayFormatter(),
    ) {
    }

    public function supports(TranscriptBlock $block): bool
    {
        if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
            return false;
        }

        $toolName = $block->meta['tool_name'] ?? null;

        return 'subagent' === $toolName
            || isset($block->meta['subagent_progress'])
            || isset($block->meta['subagent_final']);
    }

    /**
     * Build ANSI-coloured content string for the widget-tree renderer.
     *
     * Returns a single string with the glyph prefix, resolved text,
     * optional streaming suffix, and theme colour applied — no wrapping.
     * The caller (TextWidget) handles wrapping.
     */
    public function buildContent(TranscriptBlock $block, TuiTheme $theme): string
    {
        $text = $this->resolveText($block);
        $prefix = TranscriptGlyphs::GLYPH_TOOL;
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        $line = \sprintf('%s %s%s', $prefix, $text, $suffix);

        return $theme->color(ThemeColorEnum::ToolOutput, $line);
    }

    private function resolveText(TranscriptBlock $block): string
    {
        $progress = $block->meta['subagent_progress'] ?? null;
        $result = $block->meta['result'] ?? null;
        $resultText = \is_string($result) && '' !== $result ? $result : $block->text;

        if (\is_array($progress)) {
            $widget = $this->formatter->format($progress);
            if ('' !== $resultText && !$this->isRedundantHandoff($widget, $resultText)) {
                return $widget."\n\n".$this->truncateResult($resultText);
            }

            return $widget;
        }

        if ('' !== $resultText) {
            return $resultText;
        }

        return 'subagent';
    }

    private function isRedundantHandoff(string $widget, string $resultText): bool
    {
        $normalized = trim($resultText);
        if ('' === $normalized) {
            return true;
        }

        return str_contains($widget, $normalized);
    }

    private function truncateResult(string $resultText): string
    {
        $lines = explode("\n", trim($resultText));
        if (\count($lines) <= 8) {
            return trim($resultText);
        }

        return implode("\n", \array_slice($lines, 0, 8))."\n…";
    }
}
