<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
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
        if (\is_array($progress)) {
            return $this->formatter->format($progress);
        }

        if ('' !== $block->text) {
            return $block->text;
        }

        return 'subagent';
    }
}
