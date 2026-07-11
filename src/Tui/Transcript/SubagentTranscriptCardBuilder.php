<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Footer\ContextUsageFormatter;

/**
 * Builds plain (ANSI-free) line lists for themed subagent transcript cards.
 *
 * Runtime projection keeps using {@see \Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter};
 * this helper is TUI-only layout for {@see SubagentResultRenderer}.
 */
final class SubagentTranscriptCardBuilder
{
    /**
     * @param array<string, mixed> $progress
     *
     * @return list<string>
     */
    public function buildLines(array $progress, ?string $handoffAppend = null): array
    {
        $mode = \is_string($progress['mode'] ?? null) ? $progress['mode'] : 'single';

        $lines = 'parallel' === $mode
            ? $this->buildParallelLines($progress)
            : $this->buildSingleLines($progress, null);

        if (null !== $handoffAppend && '' !== trim($handoffAppend)) {
            $collapsed = $this->sanitizeInlineValue($handoffAppend);
            if ('' !== $collapsed) {
                $lines[] = 'Handoff '.$this->truncate($collapsed, 200);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $progress
     *
     * @return list<string>
     */
    private function buildSingleLines(array $progress, ?int $childIndex): array
    {
        $agentName = $this->string($progress, 'agent_name', 'subagent');
        $status = $this->normalizeStatus($this->string($progress, 'status', 'running'));
        $header = $this->formatHeaderLine($progress, $agentName, $status, $childIndex);

        $lines = [$header];

        $task = $this->string($progress, 'task_summary', '');
        if ('' !== $task) {
            $lines[] = 'Task '.$this->truncate($task, 120);
        }

        $artifactPath = $this->string($progress, 'artifact_path', '');
        $artifactId = $this->string($progress, 'artifact_id', '');
        if ('' !== $artifactPath) {
            $lines[] = 'Artifact '.$artifactPath;
        } elseif ('' !== $artifactId) {
            $lines[] = 'Artifact '.$artifactId;
        }

        $runId = $this->string($progress, 'agent_run_id', '');
        if ('' !== $runId) {
            $lines[] = 'Run '.$this->truncate($runId, 80);
        }

        $activeTool = $this->string($progress, 'active_tool', '');
        if ('' !== $activeTool && $this->isActiveStatus($status)) {
            $lines[] = 'Active '.$this->sanitizeInlineValue($activeTool);
        }

        foreach ($this->recentToolLines($progress) as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '› '.$this->sanitizeInlineValue($toolLine);
        }

        $excerpt = $this->string($progress, 'assistant_excerpt', '');
        if ('' !== $excerpt) {
            $lines[] = $this->truncate($excerpt, 200);
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
     * @param array<string, mixed> $progress
     *
     * @return list<string>
     */
    private function buildParallelLines(array $progress): array
    {
        $status = $this->normalizeStatus($this->string($progress, 'status', 'running'));
        $completed = $this->intOrNull($progress, 'completed_count') ?? 0;
        $total = max($this->intOrNull($progress, 'total_count') ?? 0, 1);
        $lines = [\sprintf('parallel subagents (%d/%d completed)', $completed, $total)];

        $children = $progress['children'] ?? [];
        if (!\is_array($children)) {
            $children = [];
        }

        $childBlocks = [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $index = $this->intOrNull($child, 'index') ?? (\count($childBlocks) + 1);
            $childBlocks[] = $this->buildSingleLines($child, $index);
        }

        foreach ($childBlocks as $block) {
            $lines[] = '';
            foreach ($block as $line) {
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

    /**
     * @param array<string, mixed> $progress
     */
    private function formatHeaderLine(array $progress, string $agentName, string $status, ?int $childIndex): string
    {
        $badge = $this->statusBadgeLabel($status);
        $glyph = $this->statusGlyph($status);
        $prefix = null === $childIndex ? '' : \sprintf('#%d ', $childIndex);
        $parts = [\sprintf('%s%s %s [%s]', $prefix, $glyph, $agentName, $badge)];

        if ($this->isActiveStatus($status)) {
            $toolCount = $this->intOrNull($progress, 'tool_count');
            if (null !== $toolCount && $toolCount > 0) {
                $parts[] = \sprintf('%d tools', $toolCount);
            }
            $tok = $this->formatTokenCompact($progress);
            if (null !== $tok) {
                $parts[] = $tok;
            }
            $elapsed = $this->formatElapsedHuman($progress);
            if (null !== $elapsed) {
                $parts[] = $elapsed;
            }
        }

        return implode(' · ', $parts);
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

    /**
     * @param array<string, mixed> $data
     */
    private function formatFooter(array $data): string
    {
        $turn = $this->intOrNull($data, 'turn_no');
        $in = $this->intOrNull($data, 'input_tokens') ?? 0;
        $out = $this->intOrNull($data, 'output_tokens') ?? 0;
        $reason = $this->intOrNull($data, 'reasoning_tokens') ?? 0;
        $cost = $data['cost'] ?? null;
        $model = $this->string($data, 'model', '');

        if (0 === $in && 0 === $out && 0 === $reason && (null === $turn || $turn <= 0) && '' === $model) {
            return '';
        }

        $parts = [];
        if (null !== $turn && $turn > 0) {
            $parts[] = \sprintf('%d turns', $turn);
        }
        if ($in > 0 || $out > 0 || $reason > 0) {
            $tokPart = \sprintf('in:%s out:%s', $this->formatTokenCount($in), $this->formatTokenCount($out));
            if ($reason > 0) {
                $tokPart .= ' R'.$this->formatTokenCount($reason);
            }
            $parts[] = $tokPart;
        }
        if (is_numeric($cost) && (float) $cost > 0.0) {
            $parts[] = '$'.number_format((float) $cost, 4, '.', '');
        }
        if ('' !== $model) {
            $parts[] = $model;
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function recentToolLines(array $data): array
    {
        $recent = $data['recent_tools'] ?? [];
        if (!\is_array($recent)) {
            return [];
        }
        $lines = [];
        foreach ($recent as $line) {
            if (\is_string($line) && '' !== $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatTokenCompact(array $data): ?string
    {
        $total = $this->intOrNull($data, 'total_tokens');
        if (null !== $total && $total > 0) {
            return $this->formatTokenCount($total).' tok';
        }
        $in = $this->intOrNull($data, 'input_tokens') ?? 0;
        $out = $this->intOrNull($data, 'output_tokens') ?? 0;
        $sum = $in + $out + ($this->intOrNull($data, 'reasoning_tokens') ?? 0);
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

    /**
     * @param array<string, mixed> $data
     */
    private function formatElapsedHuman(array $data): ?string
    {
        $ms = $this->intOrNull($data, 'elapsed_ms');
        if (null === $ms || $ms < 0) {
            return null;
        }

        $seconds = (int) floor($ms / 1000);
        if ($seconds < 60) {
            return \sprintf('%ds', $seconds);
        }
        $minutes = (int) floor($seconds / 60);
        $rem = $seconds % 60;

        return \sprintf('%dm%02ds', $minutes, $rem);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatContextUsageLine(array $progress): ?string
    {
        $model = $this->optionalModelString($progress);
        $latest = $this->resolveLatestInputTokens($progress);
        $window = $this->intOrNull($progress, 'context_window') ?? 0;
        $formatted = ContextUsageFormatter::format($model, $latest, $window);
        if (null === $formatted) {
            return null;
        }

        return 'CTX '.$formatted->text;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function resolveLatestInputTokens(array $progress): int
    {
        $latest = $this->intOrNull($progress, 'latest_input_tokens');
        if (null !== $latest && $latest > 0) {
            return $latest;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function optionalModelString(array $progress): ?string
    {
        $model = $this->string($progress, 'model', '');
        if ('' === $model) {
            return null;
        }

        return $model;
    }

    private function retrieveGuidance(string $status): string
    {
        if ('completed' === $status) {
            return 'Use agent_retrieve for full details if the inline handoff is not enough.';
        }

        return 'Use agent_retrieve (metadata/events/history) for full child details.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key, string $default): string
    {
        $v = $data[$key] ?? $default;

        return \is_string($v) && '' !== $v ? $v : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function intOrNull(array $data, string $key): ?int
    {
        if (!isset($data[$key]) || !is_numeric($data[$key])) {
            return null;
        }

        return (int) $data[$key];
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
}
