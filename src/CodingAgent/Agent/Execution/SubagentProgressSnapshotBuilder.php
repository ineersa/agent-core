<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressChildRowDTO;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressSnapshotDTO;

/**
 * Builds typed subagent_progress snapshots for parent transcript projection.
 *
 * Canonical array shape is produced only at event boundaries via
 * {@see SubagentProgressSnapshotDTO::toArray()}.
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
        ?SubagentChildProgressSummary $enrichment = null,
        string $status = 'running',
    ): SubagentProgressSnapshotDTO {
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
        ?SubagentChildProgressSummary $enrichment = null,
    ): SubagentProgressSnapshotDTO {
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

    public function singleRunning(
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        RunState $childState,
        int $elapsedMs,
        ?SubagentChildProgressSummary $enrichment = null,
        string $status = 'running',
    ): SubagentProgressSnapshotDTO {
        return $this->singleRunningFromChildTurn($agentName, $artifactId, $agentRunId, $taskSummary, $childState->turnNo, $elapsedMs, $enrichment, $status);
    }

    public function singleTerminal(
        string $status,
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        RunState $childState,
        int $elapsedMs,
        ?SubagentChildProgressSummary $enrichment = null,
    ): SubagentProgressSnapshotDTO {
        return $this->singleTerminalFromChildTurn($status, $agentName, $artifactId, $agentRunId, $taskSummary, $childState->turnNo, $elapsedMs, $enrichment);
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:?string}> $reports
     * @param array<string, int>                                                                                                                                                           $activeTurns
     * @param array<string, SubagentChildProgressSummary>                                                                                                                                  $enrichmentByAgentRunId
     */
    public function parallelSnapshot(
        array $reports,
        array $activeTurns,
        int $elapsedMs,
        array $enrichmentByAgentRunId = [],
        string $aggregateStatus = 'running',
    ): SubagentProgressSnapshotDTO {
        $sorted = array_values($reports);
        usort($sorted, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

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
            $agentRunId = $report['agentRunId'];
            $terminal = $report['terminal'];
            if ($terminal) {
                ++$completed;
            }

            $childStatus = 'running';
            if (!$terminal && AgentArtifactStatusEnum::NeedsClarification === $report['status']) {
                $childStatus = 'waiting_human';
            } elseif ($terminal && null !== $report['status']) {
                $childStatus = match ($report['status']) {
                    AgentArtifactStatusEnum::Completed => 'completed',
                    AgentArtifactStatusEnum::Failed => 'failed',
                    AgentArtifactStatusEnum::Cancelled => 'cancelled',
                    default => 'done',
                };
            }

            $enrichment = $enrichmentByAgentRunId[$agentRunId] ?? null;
            if (null !== $enrichment) {
                $aggToolCount += $enrichment->toolCount;
                $aggInput += $enrichment->inputTokens;
                $aggOutput += $enrichment->outputTokens;
                $aggReasoning += $enrichment->reasoningTokens;
                $aggTotal += $enrichment->totalTokens;
                if (null !== $enrichment->cost) {
                    $aggCost += $enrichment->cost;
                    $hasCost = true;
                }
            }

            $children[] = $this->childRow(
                index: $report['index'],
                agentName: $report['agentName'],
                status: $childStatus,
                artifactId: $report['artifactId'],
                agentRunId: $report['agentRunId'],
                taskSummary: $report['task'],
                turnNo: $activeTurns[$agentRunId] ?? 0,
                enrichment: $enrichment,
            );
        }

        return new SubagentProgressSnapshotDTO(
            mode: 'parallel',
            status: $aggregateStatus,
            elapsedMs: max(0, $elapsedMs),
            completedCount: $completed,
            totalCount: $total,
            children: $children,
            toolCount: $aggToolCount,
            inputTokens: $aggInput,
            outputTokens: $aggOutput,
            reasoningTokens: $aggReasoning,
            totalTokens: $aggTotal,
            cost: $hasCost ? $aggCost : null,
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
        ?SubagentChildProgressSummary $enrichment,
    ): SubagentProgressSnapshotDTO {
        if (null === $enrichment) {
            return new SubagentProgressSnapshotDTO(
                mode: 'single',
                status: $status,
                elapsedMs: max(0, $elapsedMs),
                agentName: $agentName,
                artifactId: $artifactId,
                agentRunId: $agentRunId,
                taskSummary: $taskSummary,
                turnNo: $childTurnNo,
            );
        }

        return new SubagentProgressSnapshotDTO(
            mode: 'single',
            status: $status,
            elapsedMs: max(0, $elapsedMs),
            agentName: $agentName,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
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
            model: (null !== $enrichment->model && '' !== $enrichment->model) ? $enrichment->model : null,
            contextWindow: $enrichment->contextWindow > 0 ? $enrichment->contextWindow : null,
            provider: (null !== $enrichment->provider && '' !== $enrichment->provider) ? $enrichment->provider : null,
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
        ?SubagentChildProgressSummary $enrichment,
    ): SubagentProgressChildRowDTO {
        if (null === $enrichment) {
            return new SubagentProgressChildRowDTO(
                index: $index,
                label: 'Step '.$index,
                agentName: $agentName,
                status: $status,
                artifactId: $artifactId,
                agentRunId: $agentRunId,
                taskSummary: $taskSummary,
                turnNo: $turnNo,
            );
        }

        return new SubagentProgressChildRowDTO(
            index: $index,
            label: 'Step '.$index,
            agentName: $agentName,
            status: $status,
            artifactId: $artifactId,
            agentRunId: $agentRunId,
            taskSummary: $taskSummary,
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
            model: (null !== $enrichment->model && '' !== $enrichment->model) ? $enrichment->model : null,
            contextWindow: $enrichment->contextWindow > 0 ? $enrichment->contextWindow : null,
            provider: (null !== $enrichment->provider && '' !== $enrichment->provider) ? $enrichment->provider : null,
            artifactPath: (null !== $enrichment->artifactPath && '' !== $enrichment->artifactPath) ? $enrichment->artifactPath : null,
            assistantExcerpt: (null !== $enrichment->assistantExcerpt && '' !== $enrichment->assistantExcerpt) ? $enrichment->assistantExcerpt : null,
            activeTool: (null !== $enrichment->activeToolLine && '' !== $enrichment->activeToolLine) ? $enrichment->activeToolLine : null,
        );
    }
}
