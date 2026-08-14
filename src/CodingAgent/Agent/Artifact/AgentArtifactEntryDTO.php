<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Immutable metadata for one parent-scoped agent artifact / child run.
 *
 * Holds the canonical artifact identity, kind discriminator, lifecycle
 * status, agent provenance, timestamps, and relative filesystem paths.
 *
 * Built by {@see AgentArtifactRegistry} during create/update operations.
 *
 * Serializable to/from JSON via Symfony Serializer with snake_case
 * field names.  The {@see AgentArtifactPathsDTO} is nested under the
 * "paths" key in serialized form.
 */
final readonly class AgentArtifactEntryDTO
{
    /** @param ?string $summary short completion/failure summary */
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim', message: 'artifact_id must not be blank')]
        public string $artifactId,
        #[Assert\NotBlank(normalizer: 'trim', message: 'parent_run_id must not be blank')]
        public string $parentRunId,
        #[Assert\NotBlank(normalizer: 'trim', message: 'agent_run_id must not be blank')]
        public string $agentRunId,
        #[Assert\NotBlank(normalizer: 'trim', message: 'agent_name must not be blank')]
        public string $agentName,
        public AgentArtifactKindEnum $kind,
        public AgentArtifactStatusEnum $status,
        #[Assert\Valid]
        public AgentArtifactPathsDTO $paths,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $completedAt = null,
        public ?string $summary = null,
        public ?string $failureReason = null,
        public ?string $needsClarification = null,
    ) {
    }
}
