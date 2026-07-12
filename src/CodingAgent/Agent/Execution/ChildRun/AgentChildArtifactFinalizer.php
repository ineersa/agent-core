<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Psr\Log\LoggerInterface;

final class AgentChildArtifactFinalizer
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildHandoffRenderer $handoffRenderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(ChildRunTerminalOutcomeDTO $outcome): void
    {
        $identity = $outcome->identity;
        $completedAt = new \DateTimeImmutable();

        $this->artifactRegistry->update(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            status: $outcome->status,
            completedAt: $completedAt,
            summary: $outcome->summary,
            failureReason: $outcome->failureReason,
            needsClarification: $outcome->needsClarification,
        );

        $handoff = $this->handoffRenderer->buildHandoffMarkdown(
            status: $outcome->status,
            summary: $outcome->summary,
            failureReason: $outcome->failureReason,
            needsClarification: $outcome->needsClarification,
            artifactId: $identity->artifactId,
            agentName: $identity->displayName,
            agentRunId: $identity->childRunId,
            childState: $outcome->childState,
        );

        $this->artifactRegistry->writeHandoff($identity->parentRunId, $identity->artifactId, $handoff);
    }

    public function logChildCancelled(ChildRunIdentityDTO $identity): void
    {
        $this->logger->info('subagent_execution.cancelled', [
            'component' => 'agent.execution',
            'event_type' => 'subagent_execution.cancelled',
            'agent_name' => $identity->displayName,
            'artifact_id' => $identity->artifactId,
        ]);
    }
}
