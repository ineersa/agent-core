<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\ChildRun\Metadata;

use Symfony\Component\Serializer\Attribute\SerializedPath;

/**
 * Typed RunStarted metadata extracted from the root RunEvent.payload via SerializedPath.
 *
 * Canonical successful RunStarted always has a nonblank model (StartRunHandler enforces it).
 * Child runs (session.kind=agent_child) also require reasoning, tools_scope, and session identity.
 * Does not replace generic {@see \Ineersa\AgentCore\Domain\Run\RunMetadata} write-side construction.
 *
 * SerializedPath attributes live on properties (AttributeLoader does not read constructor params).
 */
final readonly class RunStartedMetadataDTO
{
    #[SerializedPath('[payload][metadata][model]')]
    public string $model;

    #[SerializedPath('[payload][metadata][reasoning]')]
    public ?string $reasoning;

    /**
     * @param list<string>|null $extensions
     */
    public function __construct(
        #[SerializedPath('[payload][metadata][session]')]
        public RunStartedSessionMetadataDTO $session,
        string $model,
        ?string $reasoning = null,
        #[SerializedPath('[payload][metadata][tools_scope]')]
        public ?RunStartedToolsScopeDTO $toolsScope = null,
        #[SerializedPath('[payload][metadata][context_window]')]
        public ?int $contextWindow = null,
        #[SerializedPath('[payload][metadata][extensions]')]
        public ?array $extensions = null,
    ) {
        $model = trim($model);
        if ('' === $model) {
            throw new \InvalidArgumentException('RunStarted metadata.model is required and must be non-blank.');
        }
        $this->model = $model;

        if (null !== $reasoning) {
            $reasoning = trim($reasoning);
            $reasoning = '' === $reasoning ? null : $reasoning;
        }
        $this->reasoning = $reasoning;

        if ($this->session->isAgentChild()) {
            if (null === $this->reasoning) {
                throw new \InvalidArgumentException('RunStarted child metadata.reasoning is required and must be non-blank.');
            }
            if (null === $this->toolsScope) {
                throw new \InvalidArgumentException('RunStarted child metadata.tools_scope is required.');
            }
        }
    }

    public function isAgentChild(): bool
    {
        return $this->session->isAgentChild();
    }

    /**
     * Child tool allowlist, or null when not a child.
     *
     * @return list<string>|null
     */
    public function allowedToolsForChild(): ?array
    {
        if (!$this->isAgentChild()) {
            return null;
        }

        // Child constructor requires tools_scope; empty list means none.
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
