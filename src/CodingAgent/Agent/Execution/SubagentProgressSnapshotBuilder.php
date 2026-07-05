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
        string $agentRunId,
        string $taskSummary,
        RunState $childState,
        int $elapsedMs,
        ?SubagentChildProgressSummary $enrichment = null,
        string $status = 'running',
    ): array {
        $base = [
            'mode' => 'single',
            'status' => $status,
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'agent_run_id' => $agentRunId,
            'task_summary' => $taskSummary,
            'turn_no' => $childState->turnNo,
            'elapsed_ms' => max(0, $elapsedMs),
        ];

        return null !== $enrichment
            ? array_merge($base, $enrichment->toProgressFields())
            : $base;
    }

    /**
     * @return array<string, mixed>
     */
    public function singleTerminal(
        string $status,
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        RunState $childState,
        int $elapsedMs,
        ?SubagentChildProgressSummary $enrichment = null,
    ): array {
        $base = [
            'mode' => 'single',
            'status' => $status,
            'agent_name' => $agentName,
            'artifact_id' => $artifactId,
            'agent_run_id' => $agentRunId,
            'task_summary' => $taskSummary,
            'turn_no' => $childState->turnNo,
            'elapsed_ms' => max(0, $elapsedMs),
        ];

        return null !== $enrichment
            ? array_merge($base, $enrichment->toProgressFields())
            : $base;
    }

    /**
     * @param array<string, array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,terminal:bool,status:?AgentArtifactStatusEnum,message:string,model?:?string}> $reports
     * @param array<string, int>                                                                                                                                                           $activeTurns
     * @param array<string, SubagentChildProgressSummary>                                                                                                                                  $enrichmentByAgentRunId
     *
     * @return array<string, mixed>
     */
    public function parallelSnapshot(
        array $reports,
        array $activeTurns,
        int $elapsedMs,
        array $enrichmentByAgentRunId = [],
        string $aggregateStatus = 'running',
    ): array {
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

            $child = [
                'index' => $report['index'],
                'label' => 'Step '.$report['index'],
                'agent_name' => $report['agentName'],
                'status' => $childStatus,
                'artifact_id' => $report['artifactId'],
                'agent_run_id' => $report['agentRunId'],
                'task_summary' => $report['task'],
                'turn_no' => $activeTurns[$agentRunId] ?? 0,
            ];

            if (isset($enrichmentByAgentRunId[$agentRunId])) {
                $child = array_merge($child, $enrichmentByAgentRunId[$agentRunId]->toProgressFields());
                $e = $enrichmentByAgentRunId[$agentRunId];
                $aggToolCount += $e->toolCount;
                $aggInput += $e->inputTokens;
                $aggOutput += $e->outputTokens;
                $aggReasoning += $e->reasoningTokens;
                $aggTotal += $e->totalTokens;
                if (null !== $e->cost) {
                    $aggCost += $e->cost;
                    $hasCost = true;
                }
            }

            $children[] = $child;
        }

        $payload = [
            'mode' => 'parallel',
            'status' => $aggregateStatus,
            'completed_count' => $completed,
            'total_count' => $total,
            'elapsed_ms' => max(0, $elapsedMs),
            'children' => $children,
            'tool_count' => $aggToolCount,
            'input_tokens' => $aggInput,
            'output_tokens' => $aggOutput,
            'reasoning_tokens' => $aggReasoning,
            'total_tokens' => $aggTotal,
        ];
        if ($hasCost) {
            $payload['cost'] = $aggCost;
        }

        return $payload;
    }
}
