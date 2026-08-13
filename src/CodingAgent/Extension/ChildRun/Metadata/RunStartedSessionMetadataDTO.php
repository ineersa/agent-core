<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Typed session block from RunStarted metadata (payload.payload.metadata.session).
 *
 * Child launches set kind=agent_child; forks also set child_kind=fork.
 * childKind stays a string so AppExtension can own this DTO without depending
 * on AppAgent enums (AgentArtifactKindEnum lives under Agent/).
 */
final readonly class RunStartedSessionMetadataDTO
{
    public const string KIND_AGENT_CHILD = 'agent_child';

    public function __construct(
        public ?string $kind = null,
        #[SerializedName('child_kind')]
        public ?string $childKind = null,
        #[SerializedName('parent_run_id')]
        public ?string $parentRunId = null,
        #[SerializedName('agent_name')]
        public ?string $agentName = null,
        #[SerializedName('artifact_id')]
        public ?string $artifactId = null,
        public ?bool $interactive = null,
    ) {
    }

    public function isAgentChild(): bool
    {
        return self::KIND_AGENT_CHILD === $this->kind;
    }
}
