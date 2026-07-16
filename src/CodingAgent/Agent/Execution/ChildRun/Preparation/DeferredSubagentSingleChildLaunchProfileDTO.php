<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

/**
 * Immutable typed profile for a single deferred child launch that bypasses catalog agent-name resolution.
 */
final readonly class DeferredSubagentSingleChildLaunchProfileDTO
{
    public function __construct(
        public AgentDefinitionDTO $definition,
        public AgentArtifactKindEnum $artifactKind,
        public DeferredSubagentChildPreparationStrategyInterface $preparationStrategy,
        public string $displayAgentName,
    ) {
    }
}
