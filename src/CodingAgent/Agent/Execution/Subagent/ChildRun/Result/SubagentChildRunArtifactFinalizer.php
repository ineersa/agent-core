<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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

        $handoff = $this->handoffRenderer->buildHandoffMarkdown(
            status: $outcome->status,
            summary: $outcome->summary,
            failureReason: $outcome->failureReason,
            needsClarification: $outcome->needsClarification,
            artifactId: $identity->artifactId,
            agentName: $identity->displayName,
            agentRunId: $identity->childRunId,
            childPresentation: $outcome->childPresentation,
        );

        // Outcome and immutable handoff are checked/persisted under one parent lock.
        $this->artifactRegistry->update(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            status: $outcome->status,
            completedAt: new \DateTimeImmutable(),
            summary: $outcome->summary,
            failureReason: $outcome->failureReason,
            needsClarification: $outcome->needsClarification,
            handoffContent: $handoff,
            handoffId: null === $outcome->terminalEventCursor ? null : Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), json_encode([
                $identity->childRunId, $outcome->terminalEventCursor, $outcome->status->value,
                $outcome->summary, $outcome->failureReason, $outcome->needsClarification, $handoff,
            ], \JSON_THROW_ON_ERROR))->toRfc4122(),
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
