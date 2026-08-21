<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Psr\Log\LoggerInterface;

final class SubagentChildRunArtifactFinalizer
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly SubagentChildRunHandoffRenderer $handoffRenderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function apply(ChildRunTerminalOutcomeDTO $outcome): void
    {
        $identity = $outcome->identity;
        $completedAt = new \DateTimeImmutable();

        $prior = $this->artifactRegistry->get($identity->parentRunId, $identity->artifactId);

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

        // Pass pre-update status/summary so archived handoff index metadata reflects the
        // run that produced the previous handoff.md, not the just-written terminal outcome.
        $this->artifactRegistry->writeHandoff(
            $identity->parentRunId,
            $identity->artifactId,
            $handoff,
            archivedMeta: null === $prior ? null : [
                'status' => $prior->status,
                'summary' => $prior->summary,
            ],
        );
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
