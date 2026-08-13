<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSnapshotInterface;
use Ineersa\Tui\Footer\ContextUsageFormatter;

/**
 * Builds plain (ANSI-free) line lists for themed subagent transcript cards.
 *
 * Runtime projection keeps using {@see \Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter};
 * this helper is TUI-only layout for {@see SubagentResultRenderer}.
 *
 * Callers must pass a typed snapshot already denormalized at the wire/meta boundary.
 */
final class SubagentTranscriptCardBuilder
{
    /**
     * @return list<string>
     */
    public function buildLines(SubagentProgressSnapshotInterface $progress, ?string $handoffAppend = null): array
    {
        if ($progress instanceof SubagentProgressParallelSnapshotDTO) {
            $lines = $this->buildParallelLines($progress);
        } elseif ($progress instanceof SubagentProgressSingleSnapshotDTO) {
            $lines = $this->buildSingleLines($progress, null);
        } else {
            throw new \InvalidArgumentException('Expected single subagent_progress snapshot.');
        }

        if (null !== $handoffAppend && '' !== trim($handoffAppend)) {
            $collapsed = $this->sanitizeInlineValue($handoffAppend);
            if ('' !== $collapsed) {
                $lines[] = 'Handoff '.$this->truncate($collapsed, 200);
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function buildSingleLines(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress, ?int $childIndex): array
    {
        $agentName = $this->agentName($progress);
        $status = $this->normalizeStatus($progress->status);
        $header = $this->formatHeaderLine($progress, $agentName, $status, $childIndex);

        $lines = [$header];

        $task = $progress->taskSummary;
        if ('' !== $task) {
            $lines[] = 'Task '.$this->truncate($task, 120);
        }

        $artifactPath = $progress->artifactPath ?? '';
        $artifactId = $progress->artifactId;
        if ('' !== $artifactPath) {
            $lines[] = 'Artifact '.$artifactPath;
        } elseif ('' !== $artifactId) {
            $lines[] = 'Artifact '.$artifactId;
        }

        $runId = $progress->agentRunId;
        if ('' !== $runId) {
            $lines[] = 'Run '.$this->truncate($runId, 80);
        }

        $activeTool = $progress->activeTool ?? '';
        if ('' !== $activeTool && $this->isActiveStatus($status)) {
            $lines[] = 'Active '.$this->sanitizeInlineValue($activeTool);
        }

        foreach ($this->recentToolLines($progress) as $toolLine) {
            if ($toolLine === $activeTool) {
                continue;
            }
            $lines[] = '› '.$this->sanitizeInlineValue($toolLine);
        }

        $excerpt = $progress->assistantExcerpt ?? '';
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
     * @return list<string>
     */
    private function buildParallelLines(SubagentProgressParallelSnapshotDTO $progress): array
    {
        $status = $this->normalizeStatus($progress->status);
        $completed = $progress->completedCount;
        $total = max($progress->totalCount, 1);
        $lines = [\sprintf('parallel subagents (%d/%d completed)', $completed, $total)];

        $childBlocks = [];
        foreach ($progress->children as $child) {
            $childBlocks[] = $this->buildSingleLines($child, $child->index);
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
            $toolCount = $progress->toolCount;
            if (null !== $toolCount && $toolCount > 0) {
                $parts[] = \sprintf('%d tools', $toolCount);
            }
            $tok = $this->formatTokenCompact($progress);
            if (null !== $tok) {
                $parts[] = $tok;
            }
            if ($progress instanceof SubagentProgressSingleSnapshotDTO) {
                $elapsed = $this->formatElapsedHuman($progress->elapsedMs);
                if (null !== $elapsed) {
                    $parts[] = $elapsed;
                }
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
            $reasoning = $data->reasoning ?? '';
            $parts[] = '' !== $reasoning ? $model.' (reasoning: '.$reasoning.')' : $model;
        }

        return implode(' · ', $parts);
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

    private function formatElapsedHuman(int $ms): ?string
    {
        if ($ms < 0) {
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

    private function formatContextUsageLine(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): ?string
    {
        $model = $progress->model;
        $latest = (null !== $progress->latestInputTokens && $progress->latestInputTokens > 0)
            ? $progress->latestInputTokens
            : 0;
        $window = $progress->contextWindow ?? 0;
        $formatted = ContextUsageFormatter::format($model, $latest, $window);
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

    private function agentName(SubagentProgressSingleSnapshotDTO|SubagentProgressChildRowDTO $progress): string
    {
        return '' !== $progress->agentName ? $progress->agentName : 'subagent';
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
