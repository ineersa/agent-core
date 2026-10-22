<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Runtime\DTO\SubagentProgressSnapshotDTO;

/**
 * Builds typed subagent_progress snapshots for parent transcript projection.
 *
 * Canonical snake_case arrays are produced only at event boundaries via Symfony Serializer.
 * Every snapshot carries required launch identity (model/reasoning) via enrichment.
 */
final class SubagentProgressSnapshotBuilder
{
    public function singleFromChildTurn(
        string $agentName,
        string $artifactId,
        string $agentRunId,
        string $taskSummary,
        int $childTurnNo,
        int $elapsedMs,
        SubagentChildProgressSummary $enrichment,
        string $status = 'running',
    ): SubagentProgressSnapshotDTO {
        return new SubagentProgressSnapshotDTO(
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
            cacheReadHitPercentage: $enrichment->cacheReadHitPercentage,
        );
    }
}
