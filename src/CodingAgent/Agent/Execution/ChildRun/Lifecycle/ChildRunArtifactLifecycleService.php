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

    public function markRunning(ChildRunIdentityDTO $identity): void
    {
        $this->artifactRegistry->promoteToRunningForwardOnly(
            parentRunId: $identity->parentRunId,
            artifactId: $identity->artifactId,
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

    public function removePendingReservation(ChildRunIdentityDTO $identity): void
    {
        try {
            $this->artifactRegistry->discardPendingReservation($identity->parentRunId, $identity->artifactId);
        } finally {
            // Reservation always pre-registers this child run id in-process. Drop the cache entry even
            // when filesystem sidecar removal fails after the canonical registry row was already written,
            // so locate() cannot keep serving a discarded Pending child. A mistaken non-Pending call
            // only clears cache; locate() can rediscover from registry.json while the row still exists.
            $this->childRunDirectory->unregister($identity->childRunId);
        }
    }
}
