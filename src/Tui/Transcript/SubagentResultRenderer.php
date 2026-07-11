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
 * Builds structured subagent tool-result widgets for the transcript renderer.
 *
 * Applies themed card borders, status colours, and compact layout on top of
 * structured {@code subagent_progress} snapshots. Runtime projection text stays
 * plain via {@see \Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter}.
 */
final readonly class SubagentResultRenderer
{
    public function __construct(
        private SubagentTranscriptCardBuilder $cardBuilder = new SubagentTranscriptCardBuilder(),
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

        if (\is_array($progress)) {
            return $this->buildProgressWidget($block, $theme, $progress, $resultText);
        }

        if ('' !== $resultText) {
            return $this->buildFallbackWidget($resultText, $theme);
        }

        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        return new TextWidget($theme->color(ThemeColorEnum::ToolOutput, TranscriptGlyphs::GLYPH_TOOL.' subagent').$suffix);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function buildProgressWidget(
        TranscriptBlock $block,
        TuiTheme $theme,
        array $progress,
        string $resultText,
    ): AbstractWidget {
        $status = $this->resolveCardStatus($progress);
        $handoffMarkdown = $this->resolveHandoffMarkdown($progress, $resultText);
        $plainLines = $this->cardBuilder->buildLines($progress);
        $footerHint = $this->resolveFooterHint($plainLines, $status, $handoffMarkdown);
        $expandHandoffHint = ('' !== $handoffMarkdown && $this->handoffNeedsExpandHint($handoffMarkdown))
            ? 'Ctrl+O to expand handoff'
            : null;

        $container = new ContainerWidget();
        $container->add(new TextWidget($this->renderCard(
            $plainLines,
            $theme,
            $status,
            $progress,
            $footerHint,
            $block->streaming,
            $expandHandoffHint,
        )));

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
        $preview = $this->previewHandoffLines($handoffMarkdown);
        $mdWidget = new MarkdownWidget("### Handoff\n\n".$preview);
        $colorSpec = $theme->getPalette()->get(ThemeColorEnum::ToolOutput);
        $style = '' !== $colorSpec
            ? new Style(color: $colorSpec, padding: Padding::from([0, 0, 0, 2]))
            : new Style(padding: Padding::from([0, 0, 0, 2]));
        $mdWidget->setStyle($style);

        return $mdWidget;
    }

    private function previewHandoffLines(string $handoffMarkdown): string
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
            $body .= "\n".$preview['ellipsis'];
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

    /**
     * @param array<string, mixed> $progress
     */
    private function resolveHandoffMarkdown(array $progress, string $resultText): string
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

    /**
     * @param list<string> $plainLines
     */
    private function resolveFooterHint(array $plainLines, string $status, string $handoffMarkdown): ?string
    {
        if ([] === $plainLines) {
            return null;
        }
        $last = $plainLines[\count($plainLines) - 1];
        if (!$this->isHintLine($last)) {
            return null;
        }

        return $last;
    }

    /**
     * @param list<string>         $plainLines
     * @param array<string, mixed> $progress
     */
    private function renderCard(
        array $plainLines,
        TuiTheme $theme,
        string $status,
        array $progress,
        ?string $footerHint,
        bool $streaming,
        ?string $inCardTrailingHint = null,
    ): string {
        if ([] === $plainLines && null === $footerHint) {
            return '';
        }

        $workingLines = $plainLines;
        if (null !== $footerHint && [] !== $workingLines && $footerHint === $workingLines[\count($workingLines) - 1]) {
            array_pop($workingLines);
        }

        $mode = \is_string($progress['mode'] ?? null) ? $progress['mode'] : 'single';
        $isParallel = 'parallel' === $mode;
        $borderColor = $this->borderColorForStatus($status);
        $header = [] !== $workingLines ? array_shift($workingLines) : 'subagent';
        $top = $theme->color($borderColor, $isParallel ? '╭─ '.$header : '╭─ '.$header);
        $styled = [$top];

        $inChild = false;
        foreach ($workingLines as $line) {
            if ('' === $line) {
                $inChild = false;
                continue;
            }
            if ($isParallel && str_starts_with($line, '#')) {
                $inChild = true;
                $styled[] = $theme->color($borderColor, '├─ ').$this->styleBodyLine($theme, $line, $this->childStatusFromLine($line), true);
                continue;
            }
            $styled[] = $theme->color($borderColor, '│ ').$this->styleBodyLine($theme, $line, $status, $inChild && str_starts_with($line, '#'));
        }

        if (null !== $footerHint) {
            $styled[] = $theme->color($borderColor, '│ ').$theme->muted($footerHint);
        }
        if (null !== $inCardTrailingHint) {
            $styled[] = $theme->color($borderColor, '│ ').$theme->muted($inCardTrailingHint);
        }
        $styled[] = $theme->color($borderColor, '╰─');

        $card = implode("\n", $styled);
        if ($streaming) {
            $card .= TranscriptGlyphs::STREAMING_SUFFIX;
        }

        return $card;
    }

    private function styleBodyLine(TuiTheme $theme, string $line, string $status, bool $childHeader): string
    {
        if ($childHeader || $this->looksLikeHeaderLine($line)) {
            return $this->styleHeaderText($theme, $line, $status);
        }
        if (str_starts_with($line, 'Task ') || str_starts_with($line, 'Artifact ') || str_starts_with($line, 'Run ')) {
            return $theme->color(ThemeColorEnum::ToolTitle, $line);
        }
        if (str_starts_with($line, 'Active ') || str_starts_with($line, '› ')) {
            return $theme->color(ThemeColorEnum::ToolOutput, $line);
        }
        if (str_starts_with($line, 'Use agent_retrieve')) {
            return $theme->muted($line);
        }
        if (str_contains($line, ' turns') || str_contains($line, 'in:')) {
            return $theme->muted($line);
        }
        if (str_starts_with($line, 'CTX ')) {
            return $this->styleContextUsageLine($theme, $line);
        }

        return $theme->color(ThemeColorEnum::ToolOutput, $line);
    }

    private function styleContextUsageLine(TuiTheme $theme, string $line): string
    {
        $detail = substr($line, 4);
        $color = ThemeColorEnum::Success;
        if (preg_match('/^(\d+)%\s+(.+)$/', $detail, $m)) {
            $pct = (float) $m[1];
            $color = $pct > 75 ? ThemeColorEnum::Error : ($pct > 50 ? ThemeColorEnum::Warning : ThemeColorEnum::Success);
        }

        return $theme->color(ThemeColorEnum::Muted, 'CTX ').$theme->color($color, $detail);
    }

    private function styleHeaderText(TuiTheme $theme, string $line, string $status): string
    {
        $color = match ($status) {
            'completed' => ThemeColorEnum::Success,
            'failed' => ThemeColorEnum::Error,
            'cancelled' => ThemeColorEnum::Muted,
            'waiting_human' => ThemeColorEnum::Warning,
            default => ThemeColorEnum::Accent,
        };

        return $theme->color($color, $line);
    }

    private function childStatusFromLine(string $line): string
    {
        if (preg_match('/\[([^\]]+)\]/', $line, $m)) {
            return match ($m[1]) {
                'needs input' => 'waiting_human',
                'running' => 'running',
                'completed' => 'completed',
                'failed' => 'failed',
                'cancelled' => 'cancelled',
                default => 'running',
            };
        }

        return 'running';
    }

    private function looksLikeHeaderLine(string $line): bool
    {
        return 1 === preg_match('/^(#\d+\s+)?[●⚠✓✕◌○]\s+/u', $line);
    }

    private function isHintLine(string $line): bool
    {
        return str_starts_with($line, 'Ctrl+\\')
            || str_starts_with($line, 'Ctrl+O')
            || str_starts_with($line, 'Use agent_retrieve');
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function resolveCardStatus(array $progress): string
    {
        $status = \is_string($progress['status'] ?? null) ? $progress['status'] : 'running';

        return match ($status) {
            'needs_clarification' => 'waiting_human',
            'starting' => 'running',
            default => $status,
        };
    }

    private function borderColorForStatus(string $status): ThemeColorEnum
    {
        return match ($status) {
            'completed' => ThemeColorEnum::BorderAccent,
            'failed' => ThemeColorEnum::Error,
            'cancelled' => ThemeColorEnum::BorderMuted,
            'waiting_human' => ThemeColorEnum::Warning,
            default => ThemeColorEnum::BorderAccent,
        };
    }

    private function resolveResultText(TranscriptBlock $block): string
    {
        $result = $block->meta['result'] ?? null;

        return \is_string($result) && '' !== $result ? $result : $block->text;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function isRedundantHandoff(array $progress, string $resultText): bool
    {
        $normalized = trim($resultText);
        if ('' === $normalized) {
            return true;
        }
        $artifactId = \is_string($progress['artifact_id'] ?? null) ? $progress['artifact_id'] : '';

        return '' !== $artifactId && str_contains($normalized, $artifactId) && !str_contains($normalized, "\n\n");
    }
}
