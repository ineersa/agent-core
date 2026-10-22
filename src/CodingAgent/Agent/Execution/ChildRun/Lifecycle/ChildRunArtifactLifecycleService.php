<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;

final class ChildRunArtifactLifecycleService
{
    public function __construct(
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly AgentChildRunDirectory $childRunDirectory,
    ) {
    }

    public function ensureReservedPending(ChildRunIdentityDTO $identity): void
    {
        $entry = $this->artifactRegistry->ensureReserved(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            agentRunId: $identity->childRunId,
            agentName: $identity->displayName,
            kind: $identity->artifactKind,
        );
        $this->childRunDirectory->register($entry);
    }

    public function reservePending(ChildRunIdentityDTO $identity): void
    {
        $entry = $this->artifactRegistry->create(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
            agentRunId: $identity->childRunId,
            agentName: $identity->displayName,
            kind: $identity->artifactKind,
        );
        $this->childRunDirectory->register($entry);
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
