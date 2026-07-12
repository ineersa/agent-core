<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Port\ChildRunArtifactLifecyclePort;

final class AgentChildArtifactLifecycleAdapter implements ChildRunArtifactLifecyclePort
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
    ) {
    }

    public function reservePending(PreparedAgentChildRunDTO $prepared): void
    {
        $id = $prepared->identity;
        $entry = $this->artifactRegistry->create(
            parentRunId: $id->parentRunId,
            artifactId: $id->artifactId,
            agentRunId: $id->childRunId,
            agentName: $id->displayName,
            kind: $id->artifactKind,
        );
        $this->childRunDirectory->register($entry);
    }

    public function markRunning(ChildRunIdentityDTO $identity): void
    {
        $this->artifactRegistry->update(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            status: AgentArtifactStatusEnum::Running,
            startedAt: new \DateTimeImmutable(),
        );
    }

    public function markNeedsClarification(ChildRunIdentityDTO $identity): void
    {
        $this->artifactRegistry->update(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            status: AgentArtifactStatusEnum::NeedsClarification,
        );
    }

    public function clearNeedsClarificationToRunning(ChildRunIdentityDTO $identity): void
    {
        $entry = $this->artifactRegistry->get($identity->parentRunId, $identity->artifactId);
        if (null !== $entry && AgentArtifactStatusEnum::NeedsClarification === $entry->status) {
            $this->artifactRegistry->update(
                parentRunId: $identity->parentRunId,
                artifactId: $identity->artifactId,
                status: AgentArtifactStatusEnum::Running,
            );
        }
    }

    public function getArtifactStatus(string $parentRunId, string $artifactId): ?AgentArtifactStatusEnum
    {
        $entry = $this->artifactRegistry->get($parentRunId, $artifactId);

        return $entry?->status;
    }

    public function hasRegistryEntry(string $parentRunId, string $artifactId): bool
    {
        return null !== $this->artifactRegistry->get($parentRunId, $artifactId);
    }
}
