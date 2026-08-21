<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Narrow composition seam for structured subagent tool-result widgets.
 *
 * Progress cards are owned by {@see SubagentProgressCardWidget}. This class
 * only decides when to attach optional handoff Markdown and unstructured
 * fallback TextWidget rails around that semantic card.
 */
final readonly class SubagentResultRenderer
{
    public function __construct(
        private TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        private TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        private TranscriptLinePreviewService $linePreviewService = new TranscriptLinePreviewService(),
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

    public function buildWidget(TranscriptBlock $block, TuiTheme $theme): AbstractWidget
    {
        $progress = $block->meta['subagent_progress'] ?? null;
        $resultText = $this->resolveResultText($block);

        if ($progress instanceof SubagentProgressSnapshotInterface) {
            return $this->buildProgressWidget($block, $theme, $progress, $resultText);
        }

        if ('' !== $resultText) {
            return $this->buildFallbackWidget($resultText, $theme);
        }

        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        return new TextWidget($theme->color(ThemeColorEnum::ToolOutput, TranscriptGlyphs::GLYPH_TOOL.' subagent').$suffix);
    }

    private function buildProgressWidget(
        TranscriptBlock $block,
        TuiTheme $theme,
        SubagentProgressSnapshotInterface $progress,
        string $resultText,
    ): AbstractWidget {
        $status = $this->resolveCardStatus($progress);
        $handoffMarkdown = $this->resolveHandoffMarkdown($progress, $resultText);
        $expandHandoffHint = ('' !== $handoffMarkdown && $this->handoffNeedsExpandHint($handoffMarkdown))
            ? 'Ctrl+O to expand handoff'
            : null;

        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->add(new SubagentProgressCardWidget(
            progress: $progress,
            streaming: $block->streaming,
            expandHandoffHint: $expandHandoffHint,
        ));

        if ('' !== $handoffMarkdown) {
            $container->add($this->buildHandoffMarkdownWidget($handoffMarkdown, $theme, $status));
        }

        return $container;
    }

    private function buildFallbackWidget(string $resultText, TuiTheme $theme): TextWidget
    {
        $lines = explode("\n", trim($resultText));
        $header = $theme->color(ThemeColorEnum::BorderAccent, '╭─ subagent');
        $body = [];
        foreach ($lines as $line) {
            $body[] = $theme->color(ThemeColorEnum::BorderAccent, '│ ').$theme->color(ThemeColorEnum::ToolOutput, $line);
        }
        $bottom = $theme->color(ThemeColorEnum::BorderAccent, '╰─');

        return new TextWidget(implode("\n", array_merge([$header], $body, [$bottom])));
    }

    private function buildHandoffMarkdownWidget(string $handoffMarkdown, TuiTheme $theme, string $status): MarkdownWidget
    {
        $preview = $this->previewHandoffLines($handoffMarkdown, $theme);
        $mdWidget = new MarkdownWidget("### Handoff\n\n".$preview);
        $colorSpec = $theme->getPalette()->get(ThemeColorEnum::ToolOutput);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));
        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    private function previewHandoffLines(string $handoffMarkdown, TuiTheme $theme): string
    {
        $lines = explode("\n", $handoffMarkdown);
        $preview = $this->linePreviewService->apply(
            $lines,
            $this->displayConfig->toolResultPreviewLines,
            fullRender: false,
            displayState: $this->displayState,
        );
        $body = implode("\n", $preview['lines']);
        if (null !== $preview['ellipsis']) {
            // Keep visible ellipsis text unchanged; italic+muted via ANSI so Markdown does not restyle the indicator.
            $body .= "\n".TranscriptPreviewEllipsis::style($theme, $preview['ellipsis']);
        }

        return $body;
    }

    private function handoffNeedsExpandHint(string $handoffMarkdown): bool
    {
        if ($this->displayState->previewableBlocksExpanded) {
            return false;
        }

        $lines = explode("\n", $handoffMarkdown);

        return \count($lines) > $this->displayConfig->toolResultPreviewLines;
    }

    private function resolveHandoffMarkdown(SubagentProgressSnapshotInterface $progress, string $resultText): string
    {
        if (!$this->isTerminalCardStatus($this->resolveCardStatus($progress))) {
            return '';
        }

        if ('' === trim($resultText) || $this->isRedundantHandoff($progress, $resultText)) {
            return '';
        }

        return trim($resultText);
    }

    private function isTerminalCardStatus(string $status): bool
    {
        return \in_array($status, ['completed', 'failed', 'cancelled'], true);
    }

    private function resolveCardStatus(SubagentProgressSnapshotInterface $progress): string
    {
        return match ($progress->status()) {
            'needs_clarification' => 'waiting_human',
            'starting' => 'running',
            default => $progress->status(),
        };
    }

    private function resolveResultText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;

        return \is_string($result) && '' !== $result ? $result : $block->text;
    }

    private function isRedundantHandoff(SubagentProgressSnapshotInterface $progress, string $resultText): bool
    {
        $normalized = trim($resultText);
        if ('' === $normalized) {
            return true;
        }
        $artifactId = $progress instanceof SubagentProgressSingleSnapshotDTO
            ? $progress->artifactId
            : '';

        return '' !== $artifactId && str_contains($normalized, $artifactId) && !str_contains($normalized, "\n\n");
    }
}
