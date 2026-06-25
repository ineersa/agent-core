<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Builds normalized subagent_progress payloads for parent transcript projection.
 */
final class SubagentProgressSnapshotBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function singleRunning(
        string $agentName,
        string $artifactId,
        string $taskSummary,
        RunState $childState,
        int $elapsedMs,
    ): array {
        return [
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'task_summary' => $taskSummary,
            'turn_no' => $childState->turnNo,
            'elapsed_ms' => max(0, $elapsedMs),
        ];
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string}> $reports
     * @param array<string, int>                                                                                                                                            $activeTurns
     *
     * @return array<string, mixed>
     */
    public function parallelSnapshot(
        array $reports,
        array $activeTurns,
        int $elapsedMs,
        string $aggregateStatus = 'running',
    ): array {
        $sorted = array_values($reports);
        usort($sorted, static fn (array $a, array $b): int => $a['index'] <=> $b['index']);

        $total = \count($sorted);
        $completed = 0;
        $children = [];

        foreach ($sorted as $report) {
            $agentRunId = $report['agentRunId'];
            $terminal = $report['terminal'];
            if ($terminal) {
                ++$completed;
            }

            $childStatus = 'running';
            if ($terminal && null !== $report['status']) {
                $childStatus = match ($report['status']) {
                    AgentArtifactStatusEnum::Completed => 'completed',
                    AgentArtifactStatusEnum::Failed => 'failed',
                    AgentArtifactStatusEnum::Cancelled => 'cancelled',
                    default => 'done',
                };
            }

            $children[] = [
                'index' => $report['index'],
                'label' => 'Step '.$report['index'],
                'agent_name' => $report['agentName'],
                'status' => $childStatus,
                'artifact_id' => $report['artifactId'],
                'task_summary' => $report['task'],
                'turn_no' => $activeTurns[$agentRunId] ?? 0,
            ];
        }

        return [
            'mode' => 'parallel',
            'status' => $aggregateStatus,
            'completed_count' => $completed,
            'total_count' => $total,
            'elapsed_ms' => max(0, $elapsedMs),
            'children' => $children,
        ];
    }
}
