<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Immutable metadata for one parent-scoped agent artifact / child run.
 *
 * Holds the canonical artifact identity, lifecycle status, agent
 * provenance, timestamps, and relative filesystem paths.
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
        #[SerializedName('artifact_id')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'artifact_id must not be blank')]
        public string $artifactId,
        #[SerializedName('parent_run_id')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'parent_run_id must not be blank')]
        public string $parentRunId,
        #[SerializedName('agent_run_id')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'agent_run_id must not be blank')]
        public string $agentRunId,
        #[SerializedName('agent_name')]
        #[Assert\NotBlank(normalizer: 'trim', message: 'agent_name must not be blank')]
        public string $agentName,
        #[SerializedName('status')]
        public AgentArtifactStatusEnum $status,
        #[SerializedName('paths')]
        #[Assert\Valid]
        public AgentArtifactPathsDTO $paths,
        #[SerializedName('created_at')]
        public \DateTimeImmutable $createdAt,
        #[SerializedName('started_at')]
        public ?\DateTimeImmutable $startedAt = null,
        #[SerializedName('completed_at')]
        public ?\DateTimeImmutable $completedAt = null,
        #[SerializedName('summary')]
        public ?string $summary = null,
        #[SerializedName('failure_reason')]
        public ?string $failureReason = null,
        #[SerializedName('needs_clarification')]
        public ?string $needsClarification = null,
    ) {
    }
}
