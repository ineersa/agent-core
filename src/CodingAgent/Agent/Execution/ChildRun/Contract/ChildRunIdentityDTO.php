<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;

/**
 * Internal child-run identity. Model/reasoning are produced by launch resolvers
 * ({@see \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory::resolveLaunchIdentity},
 * {@see \Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder::resolveLaunchIdentity}) which fail closed on empty values.
 */
final readonly class ChildRunIdentityDTO
{
    public function __construct(
        public string $parentRunId,
        public string $childRunId,
        public string $artifactId,
        public string $displayName,
        public string $taskSummary,
        public string $launchModel,
        public string $launchReasoning,
        public AgentArtifactKindEnum $artifactKind,
        public int $batchIndex = 1,
    ) {
    }
}
