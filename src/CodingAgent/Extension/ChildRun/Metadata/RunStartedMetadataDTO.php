<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed RunStarted metadata (payload.payload.metadata) for CodingAgent consumers.
 *
 * Hydrated by Symfony Serializer as part of {@see RunStartedEventPayloadDTO}.
 * Does not replace generic {@see \Ineersa\AgentCore\Domain\Run\RunMetadata} write-side construction.
 */
final readonly class RunStartedMetadataDTO
{
    /**
     * @param list<string>|null $extensions
     */
    public function __construct(
        public RunStartedSessionMetadataDTO $session,
        public ?string $model = null,
        public ?string $reasoning = null,
        public ?RunStartedToolsScopeDTO $toolsScope = null,
        public ?int $contextWindow = null,
        public ?array $extensions = null,
        public ?string $provider = null,
    ) {
    }

    public function isAgentChild(): bool
    {
        return $this->session->isAgentChild();
    }

    /**
     * Child tool allowlist, or null when not a child / tools_scope.allowed_tools missing.
     *
     * @return list<string>|null
     */
    public function allowedToolsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }
        if (null === $this->toolsScope || null === $this->toolsScope->allowedTools) {
            return null;
        }

        return $this->toolsScope->allowedTools;
    }

    /**
     * Child extension allowlist:
     * - null when not an agent child
     * - empty list when extensions absent (fail closed) or empty
     * - non-empty list when present
     *
     * @return list<string>|null
     */
    public function allowedExtensionsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }

        return $this->extensions ?? [];
    }
}
