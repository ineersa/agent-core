<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;

/**
 * Result of fork child finalization.
 *
 * Produced by {@see ForkChildResultFinalizer::finalize()} and returned
 * to the fork CLI handler for reporting.
 *
 * Contains the terminal status, handoff path, error message, and
 * validation attempt count so the caller knows the outcome without
 * re-reading the artifact directory.
 */
final readonly class ForkFinalizationResultDTO
{
    /**
     * @param AgentArtifactStatusEnum $status               Terminal status
     * @param string|null             $handoffPath          Path to accepted handoff.md (null on failure/cancel)
     * @param string|null             $error                Error message (null on success)
     * @param string|null             $childRunId           Child agent run ID
     * @param int                     $validationAttempts   Number of handoff validation attempts
     * @param string|null             $candidateHandoffPath Path to the invalid candidate handoff file (for diagnostics)
     */
    public function __construct(
        public AgentArtifactStatusEnum $status,
        public ?string $handoffPath = null,
        public ?string $error = null,
        public ?string $childRunId = null,
        public int $validationAttempts = 0,
        public ?string $candidateHandoffPath = null,
    ) {
    }
}
