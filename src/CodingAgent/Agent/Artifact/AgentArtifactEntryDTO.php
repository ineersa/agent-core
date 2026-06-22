<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Immutable metadata for one parent-scoped agent artifact / child run.
 *
 * Holds the canonical artifact identity, lifecycle status, agent
 * provenance, timestamps, and relative filesystem paths.
 *
 * Built by {@see AgentArtifactRegistry} during create/update operations.
 */
final readonly class AgentArtifactEntryDTO
{
    public function __construct(
        public string $artifactId,
        public string $parentRunId,
        public string $agentRunId,
        public string $agentName,
        public AgentArtifactStatusEnum $status,
        public AgentArtifactPathsDTO $paths,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?string $summary = null,
        public ?string $failureReason = null,
        public ?string $needsClarification = null,
    ) {
    }

    /**
     * Whether the artifact is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this->status) {
            AgentArtifactStatusEnum::Completed,
            AgentArtifactStatusEnum::Failed,
            AgentArtifactStatusEnum::Cancelled,
            AgentArtifactStatusEnum::NeedsClarification => true,
            default => false,
        };
    }
}
