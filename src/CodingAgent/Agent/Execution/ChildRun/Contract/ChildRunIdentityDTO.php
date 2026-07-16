<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;

final readonly class ChildRunIdentityDTO
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $artifactId,
        public string $displayName,
        public string $taskSummary,
        public ?string $definitionModel,
        public AgentArtifactKindEnum $artifactKind,
        public int $batchIndex = 1,
    ) {
    }
}
