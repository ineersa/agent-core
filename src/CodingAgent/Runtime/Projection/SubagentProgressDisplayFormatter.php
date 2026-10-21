<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotDTO;

use function Symfony\Component\String\u;

/**
 * Builds compact inline transcript text for structured subagent progress snapshots.
 *
 * ToolResult blocks retain the full progress text, including recent tools and
 * the assistant excerpt. {@see \Ineersa\Tui\Transcript\SubagentResultRenderer}
 * renders a smaller, height-stable card and directs users to /agents-live for details.
 *
 * Accepts typed snapshots only. Wire arrays are denormalized once at the
 * RuntimeEvent projection boundary before calling this formatter.
 */
final class SubagentProgressDisplayFormatter
{
    public function format(SubagentProgressSnapshotDTO $snapshot): string
    {
        return $this->formatSingle($snapshot);
    }

    private function formatSingle(SubagentProgressSnapshotDTO $progress): string
    {
        return implode("\n", $this->formatSingleWidgetLines($progress));
    }

    /**
     * @return list<string>
     */
    private function formatSingleWidgetLines(SubagentProgressSnapshotDTO $progress): array
    {
        $status = $progress->status;
        $lines = [];
        $lines[] = \sprintf('subagent %s', $progress->agentName);

        $lines = array_merge($lines, $this->formatSingleWidgetBodyLines($progress, $progress->agentName, $status));

        if (\in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            $lines[] = $this->retrieveGuidance($status);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function formatSingleWidgetBodyLines(
        SubagentProgressSnapshotDTO $progress,
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

    private function formatRunningSummary(
        string $status,
        string $agentName,
        SubagentProgressSnapshotDTO $data,
    ): string {
        $parts = [\sprintf('%s %s', $status, $agentName)];
        if (null !== $data->cacheReadHitPercentage) {
            $parts[] = \sprintf('↻ %.0f%%', $data->cacheReadHitPercentage);
        }
        if (!\in_array($status, ['running', 'completed', 'failed', 'cancelled'], true)) {
            return implode(' | ', $parts);
        }

        if ($data->toolCount > 0) {
            $parts[] = \sprintf('%d tools', $data->toolCount);
        }
        $tok = $this->formatTokenCompact($data);
        if (null !== $tok) {
            $parts[] = $tok;
        }
        $parts[] = $this->formatElapsedHuman($data->elapsedMs);

        return implode(' | ', $parts);
    }

    private function formatFooter(SubagentProgressSnapshotDTO $data): string
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

    private function formatTokenCompact(SubagentProgressSnapshotDTO $data): ?string
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
        if ($ms < 1000) {
            return \sprintf('%dms', max(0, $ms));
        }
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
        return u($text)->truncate($max, '…')->toString();
    }
}
