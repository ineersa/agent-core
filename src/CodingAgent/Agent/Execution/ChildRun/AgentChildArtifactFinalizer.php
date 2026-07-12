<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Psr\Log\LoggerInterface;

/**
 * Canonical artifact registry updates and handoff persistence for terminal child paths.
 */
final class AgentChildArtifactFinalizer
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function finalize(
        string $parentRunId,
        string $artifactId,
        AgentArtifactStatusEnum $status,
        ?string $summary = null,
        ?string $failureReason = null,
        ?string $needsClarification = null,
        ?string $displayName = null,
        ?string $childRunId = null,
        ?RunState $childState = null,
    ): void {
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: $status,
            completedAt: $completedAt,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
        );

        $handoff = $this->handoffRenderer->buildHandoffMarkdown(
            status: $status,
            summary: $summary,
            failureReason: $failureReason,
            needsClarification: $needsClarification,
            artifactId: $artifactId,
            agentName: $displayName,
            agentRunId: $childRunId,
            childState: $childState,
        );

        $this->artifactRegistry->writeHandoff($parentRunId, $artifactId, $handoff);
    }

    public function handleCompleted(
        string $parentRunId,
        string $artifactId,
        string $displayName,
        RunState $state,
    ): string {
        $finalMessages = $this->handoffRenderer->extractLastMessage($state);
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Completed,
            summary: $finalMessages,
        );

        return $this->handoffRenderer->formatCompletedResult($displayName, $artifactId, $finalMessages);
    }

    public function handleFailed(
        string $parentRunId,
        string $artifactId,
        string $displayName,
        RunState $state,
    ): string {
        $errorMsg = $state->errorMessage ?? 'Run failed without error message.';
        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Failed,
            failureReason: $errorMsg,
            summary: $errorMsg,
        );

        return $this->handoffRenderer->formatFailedResult($displayName, $artifactId, $errorMsg);
    }

    public function handleCancelled(
        string $parentRunId,
        string $artifactId,
        string $displayName,
        RunState $state,
    ): string {
        $this->logger->info('subagent_execution.cancelled', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.cancelled',
            'agent_name' => $displayName,
            'artifact_id' => $artifactId,
        ]);

        $this->finalize(
            parentRunId: $parentRunId,
            artifactId: $artifactId,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Child run was cancelled.',
            displayName: $displayName,
            childRunId: $state->runId,
            childState: $state,
        );

        return $this->handoffRenderer->formatChildCancelledMessage($displayName, $artifactId);
    }
}
