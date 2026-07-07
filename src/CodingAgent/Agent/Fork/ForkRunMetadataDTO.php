<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fork-specific metadata contract for the metadata.json payload.
 *
 * Contains run identity, model resolution, task, and lifecycle
 * tracking for a fork child process.
 *
 * The artifact kind discriminator (subagent/fork) lives on
 * {@see AgentArtifactEntryDTO::$kind} and is intentionally NOT
 * duplicated in this metadata payload.
 *
 * Immutable value object.  Round-trips through Symfony Serializer.
 */
final readonly class ForkRunMetadataDTO
{
    public const int DEFAULT_VALIDATION_ATTEMPTS = 0;

    public function __construct(
        #[SerializedName('run_id')]
        public string $runId,

        #[SerializedName('parent_run_id')]
        public string $parentRunId,

        #[SerializedName('child_run_id')]
        public ?string $childRunId = null,

        #[SerializedName('resolved_model')]
        public ?string $resolvedModel = null,

        #[SerializedName('cwd')]
        public string $cwd = '',

        #[SerializedName('task')]
        public string $task = '',

        #[SerializedName('status')]
        public AgentArtifactStatusEnum $status = AgentArtifactStatusEnum::Pending,

        #[SerializedName('started_at')]
        public ?\DateTimeImmutable $startedAt = null,

        #[SerializedName('completed_at')]
        public ?\DateTimeImmutable $completedAt = null,

        #[SerializedName('pid')]
        public ?int $pid = null,

        #[SerializedName('error')]
        public ?string $error = null,

        #[SerializedName('validation_attempts')]
        public int $validationAttempts = self::DEFAULT_VALIDATION_ATTEMPTS,
    ) {
    }
}
