<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed session block from RunStarted metadata (payload.metadata.session).
 *
 * Child launches set kind=agent_child; forks also set child_kind=fork.
 * childKind stays a string so AppExtension can own this DTO without depending
 * on AppAgent enums (AgentArtifactKindEnum lives under Agent/).
 *
 * For agent_child sessions, parent_run_id / agent_name / artifact_id are required
 * nonblank and interactive defaults to true when omitted.
 */
final readonly class RunStartedSessionMetadataDTO
{
    public const string KIND_AGENT_CHILD = 'agent_child';

    public ?string $parentRunId;

    public ?string $agentName;

    public ?string $artifactId;

    public bool $interactive;

    public function __construct(
        public ?string $kind = null,
        public ?string $childKind = null,
        ?string $parentRunId = null,
        ?string $agentName = null,
        ?string $artifactId = null,
        ?bool $interactive = null,
    ) {
        if (self::KIND_AGENT_CHILD === $this->kind) {
            $parentRunId = null !== $parentRunId ? trim($parentRunId) : '';
            $agentName = null !== $agentName ? trim($agentName) : '';
            $artifactId = null !== $artifactId ? trim($artifactId) : '';
            if ('' === $parentRunId) {
                throw new \InvalidArgumentException('RunStarted child session.parent_run_id is required and must be non-blank.');
            }
            if ('' === $agentName) {
                throw new \InvalidArgumentException('RunStarted child session.agent_name is required and must be non-blank.');
            }
            if ('' === $artifactId) {
                throw new \InvalidArgumentException('RunStarted child session.artifact_id is required and must be non-blank.');
            }
            $this->parentRunId = $parentRunId;
            $this->agentName = $agentName;
            $this->artifactId = $artifactId;
            $this->interactive = $interactive ?? true;

            return;
        }

        $this->parentRunId = null !== $parentRunId && '' !== trim($parentRunId) ? trim($parentRunId) : null;
        $this->agentName = null !== $agentName && '' !== trim($agentName) ? trim($agentName) : null;
        $this->artifactId = null !== $artifactId && '' !== trim($artifactId) ? trim($artifactId) : null;
        $this->interactive = $interactive ?? true;
    }

    public function isAgentChild(): bool
    {
        return self::KIND_AGENT_CHILD === $this->kind;
    }
}
