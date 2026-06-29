<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Fork-specific metadata contract for the metadata.json payload.
 *
 * Contains run identity, level, model resolution, task, and lifecycle
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
    public const string CANDIDATE_HANDOFF_FILENAME = 'candidate-handoff.md';

    /**
     * @param string                  $runId                Fork artifact run ID
     * @param string                  $parentRunId          Parent session run ID
     * @param string|null             $childRunId           Child session run ID (set when child starts)
     * @param ForkLevelEnum           $level                Resolved fork level
     * @param string|null             $resolvedModel        Resolved model (null = session model)
     * @param string                  $cwd                  Working directory for the fork
     * @param string                  $task                 Fork task description
     * @param AgentArtifactStatusEnum $status               Lifecycle status
     * @param \DateTimeImmutable|null $startedAt            When the fork started
     * @param \DateTimeImmutable|null $completedAt          When the fork completed
     * @param int|null                $pid                  OS process ID of the fork
     * @param string|null             $error                Error message on failure
     * @param int                     $validationAttempts   Number of handoff validation attempts
     * @param string|null             $candidateHandoffPath Path to the invalid candidate handoff file (for diagnostics)
     * @param string|null             $validationError      Validator error message on handoff failure
     */
    public function __construct(
        #[SerializedName('run_id')]
        public string $runId,

        #[SerializedName('parent_run_id')]
        public string $parentRunId,

        #[SerializedName('child_run_id')]
        public ?string $childRunId = null,

        #[SerializedName('level')]
        public ForkLevelEnum $level = ForkLevelEnum::Middle,

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

        #[SerializedName('candidate_handoff_path')]
        public ?string $candidateHandoffPath = null,

        #[SerializedName('validation_error')]
        public ?string $validationError = null,
    ) {
    }
}
