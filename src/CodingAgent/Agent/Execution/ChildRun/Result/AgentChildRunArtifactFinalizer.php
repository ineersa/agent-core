<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Psr\Log\LoggerInterface;

final class AgentChildRunArtifactFinalizer
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunHandoffRenderer $handoffRenderer,
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
            identity: $identity,
        );

        $this->artifactRegistry->writeHandoff($identity->parentRunId, $identity->artifactId, $handoff);
    }

    public function logChildCancelled(ChildRunIdentityDTO $identity): void
    {
        $this->logger->info('child_agent_execution.cancelled', [
            'component' => 'agent.execution',
            'event_type' => 'child_agent_execution.cancelled',
            'artifact_kind' => $identity->artifactKind->value,
            'agent_name' => $identity->displayName,
            'artifact_id' => $identity->artifactId,
            'run_id' => $identity->childRunId,
        ]);
    }
}
