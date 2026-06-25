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
        $agentName = $this->string($progress, 'agent_name', 'subagent');
        $status = $this->string($progress, 'status', 'running');
        $artifactId = $this->string($progress, 'artifact_id', '');
        $task = $this->string($progress, 'task_summary', '');
        $turn = $this->intOrNull($progress, 'turn_no');
        $elapsed = $this->formatElapsedHuman($progress);

        $lines = [\sprintf('subagent %s', $agentName)];

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
        if ('' !== $excerpt && 'running' === $status) {
            $lines[] = $this->truncate($excerpt, 200);
        }

        $footer = $this->formatFooter($progress, $turn);
        if ('' !== $footer) {
            $lines[] = $footer;
        }

        if (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $progress
     */
    private function formatParallel(array $progress): string
    {
        $status = $this->string($progress, 'status', 'running');
        $completed = $this->intOrNull($progress, 'completed_count') ?? 0;
        $total = $this->intOrNull($progress, 'total_count') ?? 0;
        $elapsed = $this->formatElapsedHuman($progress);

        $lines = ['subagent parallel'];
        $header = \sprintf('running %d/%d', $completed, max($total, 1));
        $toolCount = $this->intOrNull($progress, 'tool_count');
        $tok = $this->formatTokenCompact($progress);
        $parts = [$header];
        if (null !== $toolCount && $toolCount > 0) {
            $parts[] = \sprintf('%d tools', $toolCount);
        }
        if (null !== $tok) {
            $parts[] = $tok;
        }
        if (null !== $elapsed) {
            $parts[] = $elapsed;
        }
        $lines[] = implode(', ', $parts);

        $children = $progress['children'] ?? [];
        if (!\is_array($children)) {
            $children = [];
        }

        foreach ($children as $child) {
            if (!\is_array($child)) {
                continue;
            }
            $label = $this->string($child, 'label', '');
            $agentName = $this->string($child, 'agent_name', 'agent');
            $childStatus = $this->string($child, 'status', 'running');
            $artifactId = $this->string($child, 'artifact_id', '');
            $task = $this->string($child, 'task_summary', '');
            $turn = $this->intOrNull($child, 'turn_no');
            $childTools = $this->intOrNull($child, 'tool_count');
            $childTok = $this->formatTokenCompact($child);

            $row = \sprintf('%s %s', $childStatus, '' !== $label ? $label.': ' : '');
            $row .= $agentName;
            $bits = [];
            if (null !== $childTools && $childTools > 0) {
                $bits[] = \sprintf('%d tools', $childTools);
            }
            if (null !== $childTok) {
                $bits[] = $childTok;
            }
            if (null !== $turn && 'running' === $childStatus) {
                $bits[] = \sprintf('turn %d', $turn);
            }
            if ([] !== $bits) {
                $row .= ' | '.implode(', ', $bits);
            }
            if ('' !== $artifactId) {
                $row .= ' | artifact '.$artifactId;
            }
            $lines[] = $row;
            if ('' !== $task && 'running' === $childStatus) {
                $lines[] = '  task: '.$this->truncate($task, 100);
            }
            $active = $this->string($child, 'active_tool', '');
            if ('' !== $active && 'running' === $childStatus) {
                $lines[] = '  > '.$active;
            }
        }

        $footer = $this->formatFooter($progress, null);
        if ('' !== $footer) {
            $lines[] = $footer;
        }

        if (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
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
