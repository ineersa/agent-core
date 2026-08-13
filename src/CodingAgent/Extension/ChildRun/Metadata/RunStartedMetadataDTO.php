<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

/**
 * Typed RunStarted metadata (payload.payload.metadata) for CodingAgent consumers.
 *
 * Decoded once at the persisted event boundary. Does not replace generic
 * {@see \Ineersa\AgentCore\Domain\Run\RunMetadata} write-side construction.
 */
final readonly class RunStartedMetadataDTO
{
    /**
     * @param list<string>|null $extensions Effective when extensionsKeyPresent is true
     */
    public function __construct(
        public RunStartedSessionMetadataDTO $session = new RunStartedSessionMetadataDTO(),
        public ?string $model = null,
        public ?string $reasoning = null,
        public ?RunStartedToolsScopeDTO $toolsScope = null,
        public ?int $contextWindow = null,
        public ?array $extensions = null,
        public bool $extensionsKeyPresent = false,
        public ?string $provider = null,
    ) {
    }

    public function isAgentChild(): bool
    {
        return $this->session->isAgentChild();
    }

    /**
     * Child tool allowlist, or null when not a child / tools_scope.allowed_tools missing/invalid.
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
     * Child extension allowlist semantics matching historical SubagentRunMetadataReader:
     * - null when not an agent child
     * - empty list when extensions key absent (fail closed) or empty/invalid
     * - non-empty list of trimmed class names when present
     *
     * @return list<string>|null
     */
    public function allowedExtensionsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }
        if (!$this->extensionsKeyPresent) {
            return [];
        }

        return $this->extensions ?? [];
    }
}
