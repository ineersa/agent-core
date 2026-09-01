<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;

/**
 * Internal child-run identity for artifact/lifecycle coordination.
 * Concrete launch model/reasoning live on StartRunInput metadata, not this DTO.
 */
final readonly class ChildRunIdentityDTO
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $artifactId,
        public string $displayName,
        public string $taskSummary,
        public AgentArtifactKindEnum $artifactKind,
        public int $batchIndex = 1,
    ) {
    }
}
