<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Tui\Ansi\TextWrapper;

/**
 * Renders structured subagent tool-result blocks inline in the chat transcript.
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
        $prefix = '  ●';
        $suffix = $block->streaming ? '...' : '';

        $line = \sprintf('%s %s%s', $prefix, $text, $suffix);

        return $theme->color(ThemeColorEnum::ToolOutput, $line);
    }

    /**
     * @return list<string>
     */
    public function render(TranscriptBlock $block, TuiRenderContext $context): array
    {
        $text = $this->resolveText($block);
        $prefix = '  ●';
        $color = ThemeColorEnum::ToolOutput;
        $suffix = $block->streaming ? '...' : '';

        $line = \sprintf('%s %s%s', $prefix, $text, $suffix);
        $width = max($context->terminalWidth, 1);
        $lines = TextWrapper::wrapTextWithAnsi($line, $width);

        return array_map(
            static fn (string $wrapped): string => $context->theme->color($color, $wrapped),
            $lines,
        );
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
