<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotCodec;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;

/**
 * Builds compact inline transcript text for structured subagent progress snapshots.
 *
 * Stored on ToolResult blocks as visible text; {@see \Ineersa\Tui\Transcript\SubagentResultRenderer}
 * applies the same layout for terminal rendering (kept in sync intentionally).
 *
 * Wire/meta arrays are denormalized once at the public boundary; internal callers
 * should pass typed snapshots.
 */
final class SubagentProgressDisplayFormatter
{
    public function __construct(
        private readonly SubagentProgressSnapshotCodec $codec,
    ) {
    }

    /**
     * @param SubagentProgressSnapshotInterface|array<string, mixed> $progress
     *
     * Array form is the transcript/meta public boundary; typed form is preferred
     * for already-decoded internal callers
     */
    public function format(SubagentProgressSnapshotInterface|array $progress): string
    {
        if ($progress instanceof SubagentProgressSnapshotInterface) {
            $snapshot = $progress;
        } else {
            try {
                $snapshot = $this->codec->denormalize($progress);
            } catch (\Throwable) {
                // Corrupt/incomplete transcript meta: keep projection resilient.
                return '';
            }
        }

        return $snapshot instanceof SubagentProgressParallelSnapshotDTO
            ? $this->formatParallel($snapshot)
            : $this->formatSingle($this->requireSingle($snapshot));
    }

    private function requireSingle(SubagentProgressSnapshotInterface $snapshot): SubagentProgressSingleSnapshotDTO
    {
        if (!$snapshot instanceof SubagentProgressSingleSnapshotDTO) {
            throw new \InvalidArgumentException('Expected single subagent_progress snapshot.');
        }

        return $snapshot;
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
        $agentName = $this->agentName($progress);
        $status = $progress->status;

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
     * @return list<string>
     */
    private function formatSingleWidgetBodyLines(
        SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress,
        string $agentName,
        string $status,
    ): array {
        $artifactId = $this->artifactId($progress);
        $task = $this->taskSummary($progress);
        $elapsed = $progress instanceof SubagentProgressSingleSnapshotDTO
            ? $this->formatElapsedHuman($progress->elapsedMs)
            : null;

        $lines = [];

        $summary = $this->formatRunningSummary($status, $agentName, $progress, $elapsed);
        if ('' !== $summary) {
            $lines[] = $summary;
        }

        if ('' !== $task) {
            $lines[] = 'Task: '.$this->truncate($task, 120);
        }

        $artifactPath = $progress->artifactPath ?? '';
        if ('' !== $artifactPath) {
            $lines[] = 'Artifacts: '.$artifactPath;
        } elseif ('' !== $artifactId) {
            $lines[] = 'Artifacts: '.$artifactId;
        }

        $activeTool = $progress->activeTool ?? '';
        if ('' !== $activeTool && 'running' === $status) {
            $lines[] = '> '.$activeTool;
        }

        foreach ($this->recentToolLines($progress) as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '> '.$toolLine;
        }

        $excerpt = $progress->assistantExcerpt ?? '';
        if ('' !== $excerpt) {
            $lines[] = $this->truncate($excerpt, 200);
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
        ?string $elapsed,
    ): string {
        if ('running' !== $status) {
            return $status.' '.$agentName;
        }

        $parts = [\sprintf('running %s', $agentName)];
        $toolCount = $data->toolCount;
        if (null !== $toolCount && $toolCount > 0) {
            $parts[] = \sprintf('%d tools', $toolCount);
        }
        $tok = $this->formatTokenCompact($data);
        if (null !== $tok) {
            $parts[] = $tok;
        }
        if (null !== $elapsed) {
            $parts[] = $elapsed;
        }

        return implode(' | ', $parts);
    }

    private function formatFooter(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): string
    {
        $llmSteps = $data->llmStepCount;
        $in = $data->inputTokens ?? 0;
        $out = $data->outputTokens ?? 0;
        $reason = $data->reasoningTokens ?? 0;
        $cost = $data->cost;
        $model = $data->model ?? '';

        if (0 === $in && 0 === $out && 0 === $reason && (null === $llmSteps || $llmSteps <= 0) && '' === $model) {
            return '';
        }

        $parts = [];
        if (null !== $llmSteps && $llmSteps > 0) {
            $parts[] = 1 === $llmSteps
                ? '1 LLM step'
                : \sprintf('%d LLM steps', $llmSteps);
        }
        if ($in > 0 || $out > 0 || $reason > 0) {
            $tokPart = \sprintf('in:%s out:%s', $this->formatTokenCount($in), $this->formatTokenCount($out));
            if ($reason > 0) {
                $tokPart .= ' R'.$this->formatTokenCount($reason);
            }
            $parts[] = $tokPart;
        }
        if (null !== $cost && $cost > 0.0) {
            $parts[] = '$'.number_format($cost, 4, '.', '');
        }
        if ('' !== $model) {
            $parts[] = $model;
        }

        return implode(' ', $parts);
    }

    /**
     * @return list<string>
     */
    private function recentToolLines(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): array
    {
        return $data->recentTools ?? [];
    }

    private function formatTokenCompact(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $data): ?string
    {
        $total = $data->totalTokens;
        if (null !== $total && $total > 0) {
            return $this->formatTokenCount($total).' tok';
        }
        $in = $data->inputTokens ?? 0;
        $out = $data->outputTokens ?? 0;
        $sum = $in + $out + ($data->reasoningTokens ?? 0);
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

    private function formatElapsedHuman(?int $ms): ?string
    {
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

    private function agentName(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): string
    {
        return '' !== $progress->agentName ? $progress->agentName : 'subagent';
    }

    private function artifactId(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): string
    {
        return $progress->artifactId;
    }

    private function taskSummary(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): string
    {
        return $progress->taskSummary;
    }

    private function truncate(string $text, int $max): string
    {
        if (\strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 1).'…';
    }
}
