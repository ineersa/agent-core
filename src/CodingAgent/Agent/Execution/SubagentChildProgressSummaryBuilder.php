<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;

/**
 * Maps durable deferred child lifecycle projections into progress enrichment.
 */
final class SubagentChildProgressSummaryBuilder
{
    public function fromDeferredProjection(
        DeferredChildRunLifecycleProjectionDTO $projection,
        string $artifactId,
    ): SubagentChildProgressSummary {
        return new SubagentChildProgressSummary(
            model: $projection->model,
            reasoning: $projection->reasoning,
            toolCount: $projection->toolCount,
            llmStepCount: $projection->llmStepCount,
            inputTokens: $projection->inputTokens,
            latestInputTokens: $projection->latestInputTokens,
            contextWindow: $projection->contextWindow ?? 0,
            outputTokens: $projection->outputTokens,
            reasoningTokens: $projection->reasoningTokens,
            totalTokens: $projection->totalTokens,
            cost: $projection->cost,
            artifactPath: AgentArtifactPathsDTO::forArtifactId($artifactId)->artifactDir,
            assistantExcerpt: $projection->assistantExcerpt,
            recentTools: $projection->recentTools,
            activeToolLine: $projection->activeToolLine,
        );
    }

    /**
     * Identity-only enrichment from launch model/reasoning before lifecycle exists.
     */
    public function fromLaunchIdentity(string $launchModel, string $launchReasoning, string $artifactId): SubagentChildProgressSummary
    {
        return new SubagentChildProgressSummary(
            model: $launchModel,
            reasoning: $launchReasoning,
            artifactPath: AgentArtifactPathsDTO::forArtifactId($artifactId)->artifactDir,
        );
    }
}
