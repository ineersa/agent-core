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
        $elapsed = $this->formatElapsed($progress);
        $activeTool = $this->string($progress, 'active_tool', '');

        $header = \sprintf('subagent %s %s', $agentName, $status);
        if (null !== $elapsed) {
            $header .= ' | '.$elapsed;
        }
        if (null !== $turn && $turn > 0 && 'running' === $status) {
            $header .= \sprintf(' | turn %d', $turn);
        }

        $lines = [$header];
        if ('' !== $task) {
            $lines[] = 'Task: '.$this->truncate($task, 120);
        }
        if ('' !== $activeTool && 'running' === $status) {
            $lines[] = '> '.$activeTool;
        }
        if ('' !== $artifactId) {
            $lines[] = 'Artifact: '.$artifactId;
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
        $elapsed = $this->formatElapsed($progress);

        $header = \sprintf('subagent parallel %s %d/%d', $status, $completed, max($total, 1));
        if (null !== $elapsed) {
            $header .= ' | '.$elapsed;
        }

        $lines = [$header];
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

            $row = \sprintf('%s %s', $childStatus, '' !== $label ? $label.': ' : '');
            $row .= $agentName;
            if (null !== $turn && 'running' === $childStatus) {
                $row .= \sprintf(' | turn %d', $turn);
            }
            if ('' !== $artifactId) {
                $row .= ' | artifact '.$artifactId;
            }
            $lines[] = $row;
            if ('' !== $task && 'running' === $childStatus) {
                $lines[] = '  task: '.$this->truncate($task, 100);
            }
        }

        if (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return implode("\n", $lines);
    }

    private function retrieveGuidance(string $status): string
    {
        if ('completed' === $status) {
            return 'Use agent_retrieve for metadata/history/debug if the inline handoff is not enough.';
        }

        return 'Use agent_retrieve (metadata/events/history) for full child details.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatElapsed(array $data): ?string
    {
        $ms = $this->intOrNull($data, 'elapsed_ms');
        if (null === $ms || $ms < 0) {
            return null;
        }

        $seconds = (int) floor($ms / 1000);
        $minutes = (int) floor($seconds / 60);
        $rem = $seconds % 60;

        return \sprintf('%02d:%02d', $minutes, $rem);
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
