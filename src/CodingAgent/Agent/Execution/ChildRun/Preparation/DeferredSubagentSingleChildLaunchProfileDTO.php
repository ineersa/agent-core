<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;

/**
 * Immutable typed profile for a single deferred child launch that bypasses catalog agent-name resolution.
 *
 * Optional inherited messages / model / thinking are preparation data only — they are applied when
 * building the child StartRunInput after batch reservation, never as a polymorphic preparation strategy.
 */
final readonly class DeferredSubagentSingleChildLaunchProfileDTO
{
    /**
     * @param list<AgentMessage>|null $inheritedMessages Already sanitized/compacted messages for the child
     */
    public function __construct(
        public AgentDefinitionDTO $definition,
        public AgentArtifactKindEnum $artifactKind,
        public string $displayAgentName,
        public ?array $inheritedMessages = null,
        public ?string $modelOverride = null,
        public ?string $reasoningOverride = null,
    ) {
    }
}
