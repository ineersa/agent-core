<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;

/**
 * Builds compact inline transcript text for structured subagent progress snapshots.
 *
 * Stored on ToolResult blocks as visible text; {@see \Ineersa\Tui\Transcript\SubagentResultRenderer}
 * applies the same layout for terminal rendering (kept in sync intentionally).
 *
 * Accepts typed snapshots only. Wire arrays are denormalized once at the
 * RuntimeEvent projection boundary before calling this formatter.
 */
final class SubagentProgressDisplayFormatter
{
    public function format(SubagentProgressSnapshotInterface $snapshot): string
    {
        if ($snapshot instanceof SubagentProgressParallelSnapshotDTO) {
            return $this->formatParallel($snapshot);
        }
        if (!$snapshot instanceof SubagentProgressSingleSnapshotDTO) {
            throw new \InvalidArgumentException('Expected single subagent_progress snapshot.');
        }

        return $this->formatSingle($snapshot);
    }

    private function formatSingle(SubagentProgressSingleSnapshotDTO $progress): string
    {
        return implode("\n", $this->formatSingleWidgetLines($progress, null));
    }

    /**
     * @return list<string>
     */
    private function formatSingleWidgetLines(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress, ?int $childIndex): array
    {
        $status = $progress->status;
        $lines = [];
        if (null === $childIndex) {
            $lines[] = \sprintf('subagent %s', $progress->agentName);
        } else {
            $lines[] = \sprintf('#%d subagent %s', $childIndex, $progress->agentName);
        }

        $lines = array_merge($lines, $this->formatSingleWidgetBodyLines($progress, $progress->agentName, $status));

        if (null === $childIndex && \in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function formatSingleWidgetBodyLines(
        SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress,
        string $agentName,
        string $status,
    ): array {
        $lines = [];

        $summary = $this->formatRunningSummary($status, $agentName, $progress);
        if ('' !== $summary) {
            $lines[] = $summary;
        }

        if ('' !== $progress->taskSummary) {
            $lines[] = 'Task: '.$this->truncate($progress->taskSummary, 120);
        }

        if (null !== $progress->artifactPath && '' !== $progress->artifactPath) {
            $lines[] = 'Artifacts: '.$progress->artifactPath;
        } else {
            $lines[] = 'Artifacts: '.$progress->artifactId;
        }

        $activeTool = $progress->activeTool ?? '';
        if ('' !== $activeTool && 'running' === $status) {
            $lines[] = '> '.$activeTool;
        }

        foreach ($progress->recentTools as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '> '.$toolLine;
        }

        if (null !== $progress->assistantExcerpt && '' !== $progress->assistantExcerpt) {
            $lines[] = $this->truncate($progress->assistantExcerpt, 200);
        }

        $footer = $this->formatFooter($progress);
        if ('' !== $footer) {
            $lines[] = $footer;
        }

        return $lines;
    }

    private function formatParallel(SubagentProgressParallelSnapshotDTO $progress): string
    {
        $status = $progress->status;
        $completed = $progress->completedCount;
        $total = max($progress->totalCount, 1);

        if ('running' === $status) {
            $lines = [\sprintf('parallel subagents running (%d/%d completed)', $completed, $total)];
        } else {
            $lines = [\sprintf('parallel subagents (%d/%d completed)', $completed, $total)];
        }

        $sections = [];
        foreach ($progress->children as $child) {
            $sections[] = implode("\n", $this->formatSingleWidgetLines($child, $child->index));
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

    private function formatRunningSummary(
        string $status,
        string $agentName,
        SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data,
    ): string {
        if ('running' !== $status) {
            return $status.' '.$agentName;
        }

        $parts = [\sprintf('running %s', $agentName)];
        if ($data->toolCount > 0) {
            $parts[] = \sprintf('%d tools', $data->toolCount);
        }
        $tok = $this->formatTokenCompact($data);
        if (null !== $tok) {
            $parts[] = $tok;
        }
        if ($data instanceof SubagentProgressSingleSnapshotDTO) {
            $parts[] = $this->formatElapsedHuman($data->elapsedMs);
        }

        return implode(' | ', $parts);
    }

    private function formatFooter(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): string
    {
        // Presentation: suppress all-zero usage footer even though identity is always present.
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

        return implode(' ', $parts);
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

    private function retrieveGuidance(string $status): string
    {
        if ('completed' === $status) {
            return 'Use agent_retrieve for full details if the inline handoff is not enough.';
        }

        return 'Use agent_retrieve (metadata/events/history) for full child details.';
    }

    private function truncate(string $text, int $max): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1).'…';
    }
}
