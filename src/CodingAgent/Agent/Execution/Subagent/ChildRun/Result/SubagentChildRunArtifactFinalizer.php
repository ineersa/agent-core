<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\DTO\SubagentChildRunArtifactPublicationDTO;
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
        $this->publish($this->preparePublication($outcome));
    }

    public function preparePublication(ChildRunTerminalOutcomeDTO $outcome, ?string $sourceId = null): SubagentChildRunArtifactPublicationDTO
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

        $reference = [
            $identity->childRunId, $outcome->terminalEventCursor, $outcome->status->value,
            $outcome->summary, $outcome->failureReason, $outcome->needsClarification, $handoff,
        ];
        if (null !== $sourceId) {
            $reference[] = $sourceId;
        }

        return new SubagentChildRunArtifactPublicationDTO(
            identity: $identity,
            status: $outcome->status,
            summary: $outcome->summary,
            failureReason: $outcome->failureReason,
            needsClarification: $outcome->needsClarification,
            handoffContent: $handoff,
            handoffId: null === $sourceId && null === $outcome->terminalEventCursor ? null : Uuid::v5(
                Uuid::fromString(Uuid::NAMESPACE_URL), json_encode($reference, \JSON_THROW_ON_ERROR),
            )->toRfc4122(),
        );
    }

    public function publish(SubagentChildRunArtifactPublicationDTO $publication): void
    {
        $identity = $publication->identity;
        // Outcome and immutable handoff are checked/persisted under one parent lock.
        $this->artifactRegistry->update(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            status: $publication->status,
            completedAt: new \DateTimeImmutable(),
            summary: $publication->summary,
            failureReason: $publication->failureReason,
            needsClarification: $publication->needsClarification,
            handoffContent: $publication->handoffContent,
            handoffId: $publication->handoffId,
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
