<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Semantic subagent progress card: owns typed snapshot → plain lines → themed rails.
 *
 * Style elements are registered via {@see self::styleSheetFromPalette()} in ChatScreen.
 */
final class SubagentProgressCardWidget extends AbstractWidget
{
    public function __construct(
        private readonly SubagentProgressSnapshotInterface $progress,
        private readonly bool $streaming = false,
        private readonly ?string $expandHandoffHint = null,
    ) {
    }

    public static function styleSheetFromPalette(ThemePalette $palette): StyleSheet
    {
        $rules = [];
        self::addRule($rules, 'border', $palette, ThemeColorEnum::BorderAccent);
        self::addRule($rules, 'border-failed', $palette, ThemeColorEnum::Error);
        self::addRule($rules, 'border-cancelled', $palette, ThemeColorEnum::BorderMuted);
        self::addRule($rules, 'border-waiting', $palette, ThemeColorEnum::Warning);
        self::addRule($rules, 'header-running', $palette, ThemeColorEnum::Accent);
        self::addRule($rules, 'header-completed', $palette, ThemeColorEnum::Success);
        self::addRule($rules, 'header-failed', $palette, ThemeColorEnum::Error);
        self::addRule($rules, 'header-cancelled', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'header-waiting', $palette, ThemeColorEnum::Warning);
        self::addRule($rules, 'meta', $palette, ThemeColorEnum::ToolTitle);
        self::addRule($rules, 'tool', $palette, ThemeColorEnum::ToolOutput);
        self::addRule($rules, 'body', $palette, ThemeColorEnum::ToolOutput);
        self::addRule($rules, 'muted', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'ctx-label', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'ctx-ok', $palette, ThemeColorEnum::Success);
        self::addRule($rules, 'ctx-warn', $palette, ThemeColorEnum::Warning);
        self::addRule($rules, 'ctx-error', $palette, ThemeColorEnum::Error);

        return new StyleSheet($rules);
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $width = max(1, $context->getColumns());
        $status = $this->normalizeStatus($this->progress->status());
        $plainLines = $this->buildLines($this->progress);
        $footerHint = $this->resolveFooterHint($plainLines, $status);

        if ([] === $plainLines && null === $footerHint && null === $this->expandHandoffHint) {
            return [];
        }

        $workingLines = $plainLines;
        if (null !== $footerHint && [] !== $workingLines && $footerHint === $workingLines[\count($workingLines) - 1]) {
            array_pop($workingLines);
        }

        $isParallel = $this->progress->isParallel();
        $borderEl = $this->borderElement($status);
        $header = [] !== $workingLines ? array_shift($workingLines) : 'subagent';
        $lines = [$this->fitLine($this->applyElement($borderEl, '╭─ '.$header), $width)];

        $inChild = false;
        foreach ($workingLines as $line) {
            if ('' === $line) {
                $inChild = false;
                continue;
            }
            if ($isParallel && str_starts_with($line, '#')) {
                $inChild = true;
                $childStatus = $this->childStatusFromLine($line);
                $lines[] = $this->fitLine(
                    $this->applyElement($borderEl, '├─ ').$this->styleBodyLine($line, $childStatus, true),
                    $width,
                );
                continue;
            }
            $lines[] = $this->fitLine(
                $this->applyElement($borderEl, '│ ').$this->styleBodyLine($line, $status, $inChild && str_starts_with($line, '#')),
                $width,
            );
        }

        if (null !== $footerHint) {
            $lines[] = $this->fitLine(
                $this->applyElement($borderEl, '│ ').$this->applyElement('muted', $footerHint),
                $width,
            );
        }
        if (null !== $this->expandHandoffHint) {
            $lines[] = $this->fitLine(
                $this->applyElement($borderEl, '│ ').$this->applyElement('muted', $this->expandHandoffHint),
                $width,
            );
        }

        $bottom = $this->applyElement($borderEl, '╰─');
        if ($this->streaming) {
            $bottom .= TranscriptGlyphs::STREAMING_SUFFIX;
        }
        $lines[] = $this->fitLine($bottom, $width);

        return $lines;
    }

    /**
     * @param array<string, Style> $rules
     */
    private static function addRule(array &$rules, string $element, ThemePalette $palette, ThemeColorEnum $token): void
    {
        $spec = $palette->get($token);
        if ('' === $spec) {
            return;
        }

        $rules[self::class.'::'.$element] = new Style(color: $spec);
    }

    /**
     * @return list<string>
     */
    private function buildLines(SubagentProgressSnapshotInterface $progress): array
    {
        if ($progress instanceof SubagentProgressParallelSnapshotDTO) {
            return $this->buildParallelLines($progress);
        }
        if ($progress instanceof SubagentProgressSingleSnapshotDTO) {
            return $this->buildSingleLines($progress, null);
        }

        throw new \InvalidArgumentException('Expected single subagent_progress snapshot.');
    }

    /**
     * @return list<string>
     */
    private function buildSingleLines(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress, ?int $childIndex): array
    {
        $status = $this->normalizeStatus($progress->status);
        $lines = [$this->formatHeaderLine($progress, $progress->agentName, $status, $childIndex)];

        if ('' !== $progress->taskSummary) {
            $lines[] = 'Task '.$this->truncate($progress->taskSummary, 120);
        }

        if (null !== $progress->artifactPath && '' !== $progress->artifactPath) {
            $lines[] = 'Artifact '.$progress->artifactPath;
        } else {
            $lines[] = 'Artifact '.$progress->artifactId;
        }

        $lines[] = 'Run '.$this->truncate($progress->agentRunId, 80);

        $activeTool = $progress->activeTool ?? '';
        if ('' !== $activeTool && $this->isActiveStatus($status)) {
            $lines[] = 'Active '.$this->sanitizeInlineValue($activeTool);
        }

        foreach ($progress->recentTools as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '› '.$this->sanitizeInlineValue($toolLine);
        }

        if (null !== $progress->assistantExcerpt && '' !== $progress->assistantExcerpt) {
            $lines[] = $this->truncate($progress->assistantExcerpt, 200);
        }

        $footer = $this->formatFooter($progress);
        if ('' !== $footer) {
            $lines[] = $footer;
        }

        $contextLine = $this->formatContextUsageLine($progress);
        if (null !== $contextLine) {
            $lines[] = $contextLine;
        }

        if (null === $childIndex) {
            if ($this->needsLiveHint($status)) {
                $lines[] = 'Ctrl+\\ / /agents-live to inspect, steer, or answer';
            } elseif (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
                $lines[] = $this->retrieveGuidance($status);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildParallelLines(SubagentProgressParallelSnapshotDTO $progress): array
    {
        $status = $this->normalizeStatus($progress->status);
        $completed = $progress->completedCount;
        $total = max($progress->totalCount, 1);
        $lines = [\sprintf('parallel subagents (%d/%d completed)', $completed, $total)];

        foreach ($progress->children as $child) {
            $lines[] = '';
            foreach ($this->buildSingleLines($child, $child->index) as $line) {
                $lines[] = $line;
            }
        }

        if ($this->needsLiveHint($status)) {
            $lines[] = 'Ctrl+\\ / /agents-live to inspect, steer, or answer';
        } elseif (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return $lines;
    }

    private function formatHeaderLine(
        SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress,
        string $agentName,
        string $status,
        ?int $childIndex,
    ): string {
        $badge = $this->statusBadgeLabel($status);
        $glyph = $this->statusGlyph($status);
        $prefix = null === $childIndex ? '' : \sprintf('#%d ', $childIndex);
        $parts = [\sprintf('%s%s %s [%s]', $prefix, $glyph, $agentName, $badge)];

        if ($this->isActiveStatus($status)) {
            if ($progress->toolCount > 0) {
                $parts[] = \sprintf('%d tools', $progress->toolCount);
            }
            $tok = $this->formatTokenCompact($progress);
            if (null !== $tok) {
                $parts[] = $tok;
            }
            if ($progress instanceof SubagentProgressSingleSnapshotDTO) {
                $parts[] = $this->formatElapsedHuman($progress->elapsedMs);
            }
        }

        return implode(' · ', $parts);
    }

    private function styleBodyLine(string $line, string $status, bool $childHeader): string
    {
        if ($childHeader || $this->looksLikeHeaderLine($line)) {
            return $this->applyElement($this->headerElement($status), $line);
        }
        if (str_starts_with($line, 'Task ') || str_starts_with($line, 'Artifact ') || str_starts_with($line, 'Run ')) {
            return $this->applyElement('meta', $line);
        }
        if (str_starts_with($line, 'Active ') || str_starts_with($line, '› ')) {
            return $this->applyElement('tool', $line);
        }
        if (str_starts_with($line, 'Use agent_retrieve')) {
            return $this->applyElement('muted', $line);
        }
        if (str_contains($line, ' LLM step') || str_contains($line, 'in:')) {
            return $this->applyElement('muted', $line);
        }
        if (str_starts_with($line, 'CTX ')) {
            return $this->styleContextUsageLine($line);
        }

        return $this->applyElement('body', $line);
    }

    private function styleContextUsageLine(string $line): string
    {
        $detail = substr($line, 4);
        $el = 'ctx-ok';
        if (preg_match('/^(\d+)%\s+(.+)$/', $detail, $m)) {
            $pct = (float) $m[1];
            $el = $pct > 75 ? 'ctx-error' : ($pct > 50 ? 'ctx-warn' : 'ctx-ok');
        }

        return $this->applyElement('ctx-label', 'CTX ').$this->applyElement($el, $detail);
    }

    /**
     * @param list<string> $plainLines
     */
    private function resolveFooterHint(array $plainLines, string $status): ?string
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

    private function borderElement(string $status): string
    {
        return match ($status) {
            'failed' => 'border-failed',
            'cancelled' => 'border-cancelled',
            'waiting_human' => 'border-waiting',
            default => 'border',
        };
    }

    private function headerElement(string $status): string
    {
        return match ($status) {
            'completed' => 'header-completed',
            'failed' => 'header-failed',
            'cancelled' => 'header-cancelled',
            'waiting_human' => 'header-waiting',
            default => 'header-running',
        };
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'needs_clarification' => 'waiting_human',
            'starting' => 'running',
            default => $status,
        };
    }

    private function statusGlyph(string $status): string
    {
        return match ($status) {
            'running' => '●',
            'waiting_human' => '⚠',
            'completed' => '✓',
            'failed' => '✕',
            'cancelled' => '◌',
            default => '○',
        };
    }

    private function statusBadgeLabel(string $status): string
    {
        return match ($status) {
            'waiting_human' => 'needs input',
            'running' => 'running',
            'completed' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => $status,
        };
    }

    private function needsLiveHint(string $status): bool
    {
        return \in_array($status, ['running', 'waiting_human'], true);
    }

    private function isActiveStatus(string $status): bool
    {
        return 'running' === $status;
    }

    private function formatFooter(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): string
    {
        if (
            0 === $data->inputTokens
            && 0 === $data->outputTokens
            && 0 === $data->reasoningTokens
            && $data->llmStepCount <= 0
            && (null === $data->cost || $data->cost <= 0.0)
        ) {
            return '';
        }

        $parts = [];
        if ($data->llmStepCount > 0) {
            $parts[] = 1 === $data->llmStepCount
                ? '1 LLM step'
                : \sprintf('%d LLM steps', $data->llmStepCount);
        }
        if ($data->inputTokens > 0 || $data->outputTokens > 0 || $data->reasoningTokens > 0) {
            $tokPart = \sprintf(
                'in:%s out:%s',
                $this->formatTokenCount($data->inputTokens),
                $this->formatTokenCount($data->outputTokens),
            );
            if ($data->reasoningTokens > 0) {
                $tokPart .= ' R'.$this->formatTokenCount($data->reasoningTokens);
            }
            $parts[] = $tokPart;
        }
        if (null !== $data->cost && $data->cost > 0.0) {
            $parts[] = '$'.number_format($data->cost, 4, '.', '');
        }
        if ('' !== $data->model) {
            $parts[] = '' !== $data->reasoning
                ? $data->model.' (reasoning: '.$data->reasoning.')'
                : $data->model;
        }

        return implode(' · ', $parts);
    }

    private function formatTokenCompact(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): ?string
    {
        if ($data->totalTokens > 0) {
            return $this->formatTokenCount($data->totalTokens).' tok';
        }
        $sum = $data->inputTokens + $data->outputTokens + $data->reasoningTokens;
        if ($sum <= 0) {
            return null;
        }

        return $this->formatTokenCount($sum).' tok';
    }

    private function formatTokenCount(int $n): string
    {
        if ($n >= 1_000_000) {
            return rtrim(rtrim(number_format($n / 1_000_000, 1, '.', ''), '0'), '.').'M';
        }
        if ($n >= 1000) {
            return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.').'k';
        }

        return (string) $n;
    }

    private function formatElapsedHuman(int $ms): string
    {
        $seconds = (int) floor(max(0, $ms) / 1000);
        if ($seconds < 60) {
            return \sprintf('%ds', $seconds);
        }
        $minutes = (int) floor($seconds / 60);
        $rem = $seconds % 60;

        return \sprintf('%dm%02ds', $minutes, $rem);
    }

    private function formatContextUsageLine(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): ?string
    {
        $latest = $progress->latestInputTokens > 0 ? $progress->latestInputTokens : 0;
        $window = $progress->contextWindow ?? 0;
        $formatted = ContextUsageFormatter::format($progress->model, $latest, $window);
        if (null === $formatted) {
            return null;
        }

        return 'CTX '.$formatted->text;
    }

    private function retrieveGuidance(string $status): string
    {
        if ('completed' === $status) {
            return 'Use agent_retrieve for full details if the inline handoff is not enough.';
        }

        return 'Use agent_retrieve (metadata/events/history) for full child details.';
    }

    private function sanitizeInlineValue(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', str_replace(["\r", "\n", "\t"], ' ', $text)) ?? $text;

        return trim($normalized);
    }

    private function truncate(string $text, int $max): string
    {
        $text = $this->sanitizeInlineValue($text);
        if ('' === $text) {
            return '';
        }
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1).'…';
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

    private function fitLine(string $line, int $width): string
    {
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
        }
        if (AnsiUtils::visibleWidth($line) <= $width) {
            return $line;
        }

        return AnsiUtils::truncateToWidth($line, $width, ellipsis: '…');
    }
}
