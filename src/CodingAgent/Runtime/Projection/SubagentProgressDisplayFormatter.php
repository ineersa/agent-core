<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Builds compact inline transcript text for structured subagent progress snapshots.
 *
 * Stored on ToolResult blocks as visible text; {@see \Ineersa\Tui\Transcript\SubagentResultRenderer}
 * applies the same layout for terminal rendering (kept in sync intentionally).
 */
final class SubagentProgressDisplayFormatter
{
    /**
     * @param array<string, mixed> $progress Normalized subagent_progress payload
     */
    public function format(array $progress): string
    {
        $mode = \is_string($progress['mode'] ?? null) ? $progress['mode'] : 'single';

        return 'parallel' === $mode
            ? $this->formatParallel($progress)
            : $this->formatSingle($progress);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatSingle(array $progress): string
    {
        return implode("\n", $this->formatSingleWidgetLines($progress, null));
    }

    /**
     * @param array<string, mixed> $progress
     *
     * @return list<string>
     */
    private function formatSingleWidgetLines(array $progress, ?int $childIndex): array
    {
        $agentName = $this->string($progress, 'agent_name', 'subagent');
        $status = $this->string($progress, 'status', 'running');

        $lines = [];
        if (null === $childIndex) {
            $lines[] = \sprintf('subagent %s', $agentName);
        } else {
            $lines[] = \sprintf('#%d subagent %s', $childIndex, $agentName);
        }

        $lines = array_merge($lines, $this->formatSingleWidgetBodyLines($progress, $agentName, $status));

        if (null === $childIndex && \in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return $lines;
    }

    /**
     * Shared body for single and per-child parallel widgets (header line excluded).
     *
     * @param array<string, mixed> $progress
     *
     * @return list<string>
     */
    private function formatSingleWidgetBodyLines(array $progress, string $agentName, string $status): array
    {
        $artifactId = $this->string($progress, 'artifact_id', '');
        $task = $this->string($progress, 'task_summary', '');
        $turn = $this->intOrNull($progress, 'turn_no');
        $elapsed = $this->formatElapsedHuman($progress);

        $lines = [];

        $summary = $this->formatRunningSummary($status, $agentName, $progress, $elapsed, $turn);
        if ('' !== $summary) {
            $lines[] = $summary;
        }

        if ('' !== $task) {
            $lines[] = 'Task: '.$this->truncate($task, 120);
        }

        $artifactPath = $this->string($progress, 'artifact_path', '');
        if ('' !== $artifactPath) {
            $lines[] = 'Artifacts: '.$artifactPath;
        } elseif ('' !== $artifactId) {
            $lines[] = 'Artifacts: '.$artifactId;
        }

        $activeTool = $this->string($progress, 'active_tool', '');
        if ('' !== $activeTool && 'running' === $status) {
            $lines[] = '> '.$activeTool;
        }

        foreach ($this->recentToolLines($progress) as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '> '.$toolLine;
        }

        $excerpt = $this->string($progress, 'assistant_excerpt', '');
        if ('' !== $excerpt) {
            $lines[] = $this->truncate($excerpt, 200);
        }

        $footer = $this->formatFooter($progress, $turn);
        if ('' !== $footer) {
            $lines[] = $footer;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatParallel(array $progress): string
    {
        $status = $this->string($progress, 'status', 'running');
        $completed = $this->intOrNull($progress, 'completed_count') ?? 0;
        $total = max($this->intOrNull($progress, 'total_count') ?? 0, 1);

        if ('running' === $status) {
            $lines = [\sprintf('parallel subagents running (%d/%d completed)', $completed, $total)];
        } else {
            $lines = [\sprintf('parallel subagents (%d/%d completed)', $completed, $total)];
        }

        $children = $progress['children'] ?? [];
        if (!\is_array($children)) {
            $children = [];
        }

        $sections = [];
        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $index = $this->intOrNull($child, 'index') ?? (\count($sections) + 1);
            $sections[] = implode("\n", $this->formatSingleWidgetLines($child, $index));
        }

        if ([] !== $sections) {
            $lines[] = '';
            $lines[] = implode("\n\n", $sections);
        }

        if (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = '';
            $lines[] = $this->retrieveGuidance($status);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatRunningSummary(string $status, string $agentName, array $data, ?string $elapsed, ?int $turn): string
    {
        if ('running' !== $status) {
            return $status.' '.$agentName;
        }

        $parts = [\sprintf('running %s', $agentName)];
        $toolCount = $this->intOrNull($data, 'tool_count');
        if (null !== $toolCount && $toolCount > 0) {
            $parts[] = \sprintf('%d tools', $toolCount);
        }
        $tok = $this->formatTokenCompact($data);
        if (null !== $tok) {
            $parts[] = $tok;
        }
        if (null !== $elapsed) {
            $parts[] = $elapsed;
        } elseif (null !== $turn && $turn > 0) {
            $parts[] = \sprintf('turn %d', $turn);
        }

        return implode(' | ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatFooter(array $data, ?int $turnOverride): string
    {
        $turn = $turnOverride ?? $this->intOrNull($data, 'turn_no');
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

        return implode(' ', $parts);
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

    private function truncate(string $text, int $max): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1).'…';
    }
}
