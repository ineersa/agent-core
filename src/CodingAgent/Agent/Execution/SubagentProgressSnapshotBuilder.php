<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressParallelSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\SubagentProgress\SubagentProgressSingleSnapshotDTO;

/**
 * Builds typed subagent_progress snapshots for parent transcript projection.
 *
 * Canonical snake_case arrays are produced only at event boundaries via Symfony Serializer.
 * Every snapshot carries required launch identity (model/reasoning) via enrichment.
 */
final class SubagentProgressSnapshotBuilder
{
    public function singleRunningFromChildTurn(
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        int $childTurnNo,
        int $elapsedMs,
        SubagentChildProgressSummary $enrichment,
        string $status = 'running',
    ): SubagentProgressSingleSnapshotDTO {
        return $this->single(
            status: $status,
            agentName: $agentName,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
            childTurnNo: $childTurnNo,
            elapsedMs: $elapsedMs,
            enrichment: $enrichment,
        );
    }

    public function singleTerminalFromChildTurn(
        string $status,
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        int $childTurnNo,
        int $elapsedMs,
        SubagentChildProgressSummary $enrichment,
    ): SubagentProgressSingleSnapshotDTO {
        return $this->single(
            status: $status,
            agentName: $agentName,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
            childTurnNo: $childTurnNo,
            elapsedMs: $elapsedMs,
            enrichment: $enrichment,
        );
    }

    /**
     * @param array<string, SubagentProgressParallelChildReportDTO> $reports
     * @param array<string, int>                                    $activeTurns
     * @param array<string, SubagentChildProgressSummary>           $enrichmentByAgentRunId
     */
    public function parallelSnapshot(
        array $reports,
        array $activeTurns,
        int $elapsedMs,
        array $enrichmentByAgentRunId,
        string $aggregateStatus = 'running',
    ): SubagentProgressParallelSnapshotDTO {
        $sorted = array_values($reports);
        usort($sorted, static fn (SubagentProgressParallelChildReportDTO $a, SubagentProgressParallelChildReportDTO $b): int => $a->index <=> $b->index);

        $total = \count($sorted);
        $completed = 0;
        $children = [];
        $aggToolCount = 0;
        $aggInput = 0;
        $aggOutput = 0;
        $aggReasoning = 0;
        $aggTotal = 0;
        $aggCost = 0.0;
        $hasCost = false;

        foreach ($sorted as $report) {
            $agentRunId = $report->agentRunId;
            $terminal = $report->terminal;
            if ($terminal) {
                ++$completed;
            }

            $childStatus = 'running';
            if (!$terminal && AgentArtifactStatusEnum::NeedsClarification === $report->status) {
                $childStatus = 'waiting_human';
            } elseif ($terminal) {
                $childStatus = match ($report->status) {
                    AgentArtifactStatusEnum::Completed => 'completed',
                    AgentArtifactStatusEnum::Failed => 'failed',
                    AgentArtifactStatusEnum::Cancelled => 'cancelled',
                    default => 'done',
                };
            }

            $enrichment = $enrichmentByAgentRunId[$agentRunId] ?? null;
            if (null === $enrichment) {
                throw new \InvalidArgumentException(\sprintf('Missing required progress enrichment for agent run "%s".', $agentRunId));
            }

            $aggToolCount += $enrichment->toolCount;
            $aggInput += $enrichment->inputTokens;
            $aggOutput += $enrichment->outputTokens;
            $aggReasoning += $enrichment->reasoningTokens;
            $aggTotal += $enrichment->totalTokens;
            if (null !== $enrichment->cost) {
                $aggCost += $enrichment->cost;
                $hasCost = true;
            }

            $children[] = $this->childRow(
                index: $report->index,
                agentName: $report->agentName,
                status: $childStatus,
                artifactId: $report->artifactId,
                agentRunId: $report->agentRunId,
                taskSummary: $report->task,
                turnNo: $activeTurns[$agentRunId] ?? 0,
                enrichment: $enrichment,
            );
        }

        return new SubagentProgressParallelSnapshotDTO(
            mode: 'parallel',
            status: $aggregateStatus,
            completedCount: $completed,
            totalCount: $total,
            elapsedMs: max(0, $elapsedMs),
            children: $children,
            toolCount: $aggToolCount,
            inputTokens: $aggInput,
            outputTokens: $aggOutput,
            reasoningTokens: $aggReasoning,
            totalTokens: $aggTotal,
            cost: $hasCost && $aggCost > 0.0 ? $aggCost : null,
        );
    }

    private function single(
        string $status,
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        int $childTurnNo,
        int $elapsedMs,
        SubagentChildProgressSummary $enrichment,
    ): SubagentProgressSingleSnapshotDTO {
        return new SubagentProgressSingleSnapshotDTO(
            mode: 'single',
            status: $status,
            agentName: $agentName,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
            model: $enrichment->model,
            reasoning: $enrichment->reasoning,
            elapsedMs: max(0, $elapsedMs),
            turnNo: $childTurnNo,
            toolCount: $enrichment->toolCount,
            llmStepCount: $enrichment->llmStepCount,
            inputTokens: $enrichment->inputTokens,
            latestInputTokens: $enrichment->latestInputTokens,
            outputTokens: $enrichment->outputTokens,
            reasoningTokens: $enrichment->reasoningTokens,
            totalTokens: $enrichment->totalTokens,
            recentTools: $enrichment->recentTools,
            cost: (null !== $enrichment->cost && $enrichment->cost > 0.0) ? $enrichment->cost : null,
            contextWindow: $enrichment->contextWindow > 0 ? $enrichment->contextWindow : null,
            artifactPath: (null !== $enrichment->artifactPath && '' !== $enrichment->artifactPath) ? $enrichment->artifactPath : null,
            assistantExcerpt: (null !== $enrichment->assistantExcerpt && '' !== $enrichment->assistantExcerpt) ? $enrichment->assistantExcerpt : null,
            activeTool: (null !== $enrichment->activeToolLine && '' !== $enrichment->activeToolLine) ? $enrichment->activeToolLine : null,
        );
    }

    private function childRow(
        int $index,
        string $agentName,
        string $status,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        int $turnNo,
        SubagentChildProgressSummary $enrichment,
    ): SubagentProgressChildRowDTO {
        return new SubagentProgressChildRowDTO(
            index: $index,
            agentName: $agentName,
            status: $status,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
            model: $enrichment->model,
            reasoning: $enrichment->reasoning,
            turnNo: $turnNo,
            toolCount: $enrichment->toolCount,
            llmStepCount: $enrichment->llmStepCount,
            inputTokens: $enrichment->inputTokens,
            latestInputTokens: $enrichment->latestInputTokens,
            outputTokens: $enrichment->outputTokens,
            reasoningTokens: $enrichment->reasoningTokens,
            totalTokens: $enrichment->totalTokens,
            recentTools: $enrichment->recentTools,
            cost: (null !== $enrichment->cost && $enrichment->cost > 0.0) ? $enrichment->cost : null,
            contextWindow: $enrichment->contextWindow > 0 ? $enrichment->contextWindow : null,
            artifactPath: (null !== $enrichment->artifactPath && '' !== $enrichment->artifactPath) ? $enrichment->artifactPath : null,
            assistantExcerpt: (null !== $enrichment->assistantExcerpt && '' !== $enrichment->assistantExcerpt) ? $enrichment->assistantExcerpt : null,
            activeTool: (null !== $enrichment->activeToolLine && '' !== $enrichment->activeToolLine) ? $enrichment->activeToolLine : null,
        );
    }
}
