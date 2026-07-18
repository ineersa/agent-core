<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

/**
 * Immutable typed profile for a single deferred child launch that bypasses catalog agent-name resolution.
 *
 * Inherited messages are required preparation data applied when building the child StartRunInput
 * after batch reservation. Explicit model override is carried on {@see AgentDefinitionDTO::$model};
 * reasoning remains on this profile because definition thinking is not the fork launch override path.
 */
final readonly class DeferredSubagentSingleChildLaunchProfileDTO
{
    /**
     * @param list<AgentMessage> $inheritedMessages Already sanitized/compacted messages for the child
     */
    public function __construct(
        public AgentDefinitionDTO $definition,
        public AgentArtifactKindEnum $artifactKind,
        public string $displayAgentName,
        public array $inheritedMessages,
        public ?string $reasoningOverride = null,
    ) {
    }
}
