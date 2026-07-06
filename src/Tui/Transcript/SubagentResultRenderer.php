<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Style;

/**
 * Builds structured subagent tool-result content for the widget-tree renderer.
 *
 * Applies themed card borders, status colours, and compact layout on top of
 * structured {@code subagent_progress} snapshots. Runtime projection text stays
 * plain via {@see \Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter}.
 */
final readonly class SubagentResultRenderer
{
    public function __construct(
        private SubagentTranscriptCardBuilder $cardBuilder = new SubagentTranscriptCardBuilder(),
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
     * Build ANSI-coloured card content for the widget-tree renderer.
     */
    public function buildContent(TranscriptBlock $block, TuiTheme $theme): string
    {
        $progress = $block->meta['subagent_progress'] ?? null;
        $resultText = $this->resolveResultText($block);
        $suffix = $block->streaming ? TranscriptGlyphs::STREAMING_SUFFIX : '';

        if (\is_array($progress)) {
            $handoff = ('' !== $resultText && !$this->isRedundantHandoff($progress, $resultText))
                ? $this->truncateResult($resultText)
                : null;
            $plainLines = $this->cardBuilder->buildLines($progress, $handoff);
            $status = $this->resolveCardStatus($progress);
            $card = $this->renderCard($plainLines, $theme, $status, $progress);

            return $card.$suffix;
        }

        if ('' !== $resultText) {
            return $this->renderFallbackCard($resultText, $theme).$suffix;
        }

        return $theme->color(ThemeColorEnum::ToolOutput, TranscriptGlyphs::GLYPH_TOOL.' subagent').$suffix;
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

    /**
     * @param list<string>         $plainLines
     * @param array<string, mixed> $progress
     */
    private function renderCard(array $plainLines, TuiTheme $theme, string $status, array $progress): string
    {
        if ([] === $plainLines) {
            return '';
        }

        $mode = \is_string($progress['mode'] ?? null) ? $progress['mode'] : 'single';
        $isParallel = 'parallel' === $mode;
        $borderColor = $this->borderColorForStatus($status);
        $header = array_shift($plainLines);
        $footerHint = null;
        if ([] !== $plainLines && $this->isHintLine($plainLines[\count($plainLines) - 1])) {
            $footerHint = array_pop($plainLines);
        }

        $top = $theme->color($borderColor, $isParallel ? '╭─ '.$header : '╭─ '.$header);
        $styled = [$top];

        $inChild = false;
        foreach ($plainLines as $line) {
            if ('' === $line) {
                $inChild = false;
                continue;
            }
            if ($isParallel && str_starts_with($line, '#')) {
                $inChild = true;
                $styled[] = $theme->color($borderColor, '├─ ').$this->styleBodyLine($theme, $line, $this->childStatusFromLine($line), true);
                continue;
            }
            if (str_starts_with($line, 'Handoff')) {
                $styled[] = $theme->color($borderColor, '│ ').$theme->color(ThemeColorEnum::ToolTitle, $line);
                continue;
            }
            $rail = $isParallel && $inChild ? '│ ' : '│ ';
            $styled[] = $theme->color($borderColor, $rail).$this->styleBodyLine($theme, $line, $status, $inChild && str_starts_with($line, '#'));
        }

        if (null !== $footerHint) {
            $styled[] = $theme->color($borderColor, '╰─ ').$theme->muted($footerHint);
        } else {
            $styled[] = $theme->color($borderColor, '╰─');
        }

        return $this->applyOptionalBackground($theme, implode("\n", $styled), $status);
    }

    private function renderFallbackCard(string $resultText, TuiTheme $theme): string
    {
        $lines = explode("\n", trim($resultText));
        $header = $theme->color(ThemeColorEnum::BorderAccent, '╭─ subagent');
        $body = [];
        foreach ($lines as $line) {
            $body[] = $theme->color(ThemeColorEnum::BorderAccent, '│ ').$theme->color(ThemeColorEnum::ToolOutput, $line);
        }
        $bottom = $theme->color(ThemeColorEnum::BorderAccent, '╰─');

        return implode("\n", array_merge([$header], $body, [$bottom]));
    }

    private function styleBodyLine(TuiTheme $theme, string $line, string $status, bool $childHeader): string
    {
        if ($childHeader || $this->looksLikeHeaderLine($line)) {
            return $this->styleHeaderText($theme, $line, $status);
        }
        if (str_starts_with($line, 'Task ') || str_starts_with($line, 'Artifact ') || str_starts_with($line, 'Run ')) {
            return $theme->color(ThemeColorEnum::ToolTitle, $line);
        }
        if (str_starts_with($line, 'Active ') || str_starts_with($line, 'Last ')) {
            return $theme->accent($line);
        }
        if (str_starts_with($line, 'Use agent_retrieve')) {
            return $theme->muted($line);
        }
        if (str_contains($line, ' turns') || str_contains($line, 'in:')) {
            return $theme->muted($line);
        }

        return $theme->color(ThemeColorEnum::ToolOutput, $line);
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
            || str_starts_with($line, 'Use agent_retrieve');
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

    private function applyOptionalBackground(TuiTheme $theme, string $text, string $status): string
    {
        $bgToken = match ($status) {
            'completed' => ThemeColorEnum::ToolSuccessBg,
            'failed' => ThemeColorEnum::ToolErrorBg,
            'cancelled' => ThemeColorEnum::ToolPendingBg,
            'waiting_human', 'running' => ThemeColorEnum::ToolPendingBg,
            default => ThemeColorEnum::ToolPendingBg,
        };

        $spec = $theme->getPalette()->get($bgToken);
        if ('' === $spec) {
            return $text;
        }

        try {
            return (new Style(background: $spec))->apply($text);
        } catch (\Throwable) {
            return $text;
        }
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

    private function truncateResult(string $resultText): string
    {
        $lines = explode("\n", trim($resultText));
        if (\count($lines) <= 8) {
            return trim($resultText);
        }

        return implode("\n", \array_slice($lines, 0, 8))."\n…";
    }
}
