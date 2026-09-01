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
            return $this->buildFallbackWidget($resultText, $theme, $this->resultColor($block));
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
        $resultColor = $this->resultColor($block);
        $isError = ThemeColorEnum::Error === $resultColor;
        $handoffMarkdown = $isError ? '' : $this->resolveHandoffMarkdown($progress, $resultText);
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

        if ($isError && '' !== trim($resultText)) {
            $container->add($this->buildResultTextWidget($resultText, $theme, $resultColor));
        } elseif ('' !== $handoffMarkdown) {
            $container->add($this->buildHandoffMarkdownWidget($handoffMarkdown, $theme));
        }

        return $container;
    }

    private function buildFallbackWidget(string $resultText, TuiTheme $theme, ThemeColorEnum $resultColor): TextWidget
    {
        $lines = explode("\n", trim($resultText));
        $header = $theme->color(ThemeColorEnum::BorderAccent, '╭─ subagent');
        $body = [];
        foreach ($lines as $line) {
            $body[] = $theme->color(ThemeColorEnum::BorderAccent, '│ ').$theme->color($resultColor, $line);
        }
        $bottom = $theme->color(ThemeColorEnum::BorderAccent, '╰─');

        return new TextWidget(implode("\n", array_merge([$header], $body, [$bottom])));
    }

    private function buildResultTextWidget(string $resultText, TuiTheme $theme, ThemeColorEnum $resultColor): TextWidget
    {
        $widget = new TextWidget($theme->color($resultColor, trim($resultText)));
        $widget->setStyle(new Style(padding: Padding::from([0, 0, 0, 2])));

        return $widget;
    }

    private function resultColor(TranscriptBlock $block): ThemeColorEnum
    {
        return true === ($block->meta['is_error'] ?? false)
            ? ThemeColorEnum::Error
            : ThemeColorEnum::ToolOutput;
    }

    private function buildHandoffMarkdownWidget(string $handoffMarkdown, TuiTheme $theme): AbstractWidget
    {
        $lines = explode("\n", $handoffMarkdown);
        $preview = $this->linePreviewService->apply(
            $lines,
            $this->displayConfig->toolResultPreviewLines,
            fullRender: false,
            displayState: $this->displayState,
        );

        $mdWidget = new MarkdownWidget("### Handoff\n\n".implode("\n", $preview['lines']));
        $handoffPadding = Padding::from([0, 0, 0, 2]);
        $colorSpec = $theme->getPalette()->get(ThemeColorEnum::ToolOutput);
        $mdWidget->setStyle(
            '' !== $colorSpec
                ? new Style(color: $colorSpec, padding: $handoffPadding)
                : new Style(padding: $handoffPadding),
        );

        if (null === $preview['ellipsis']) {
            return $mdWidget;
        }

        // MarkdownWidget strips ESC sequences; keep the styled ellipsis as a sibling TextWidget.
        $ellipsis = new TextWidget(TranscriptPreviewEllipsis::style($theme, $preview['ellipsis']));
        $ellipsis->setStyle(new Style(padding: $handoffPadding));

        $handoff = new ContainerWidget();
        $handoff->setStyle(new Style(direction: Direction::Vertical));
        $handoff->add($mdWidget);
        $handoff->add($ellipsis);

        return $handoff;
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
