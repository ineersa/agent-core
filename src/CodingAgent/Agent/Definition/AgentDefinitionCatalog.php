<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Immutable registry holding discovered agent definitions and diagnostics.
 *
 * Definitions are stored by name (deterministic). Every valid discovered
 * definition is launchable; remove a definition by deleting/moving its file.
 *
 * @internal
 */
final readonly class AgentDefinitionCatalog
{
    /** @var array<string, AgentDefinitionDTO> name → definition */
    private array $byName;

    /** @var list<AgentDefinitionDiagnosticDTO> */
    private array $diagnostics;

    /**
     * @param list<AgentDefinitionDTO>           $definitions
     * @param list<AgentDefinitionDiagnosticDTO> $diagnostics
     */
    public function __construct(
        array $definitions,
        array $diagnostics = [],
    ) {
        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->name] = $definition;
        }
        $this->byName = $byName;
        $this->diagnostics = $diagnostics;
    }

    /**
     * Look up a definition by name. Returns null when not found.
     */
    public function get(string $name): ?AgentDefinitionDTO
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Look up a definition by name. Throws when the agent is not found.
     *
     * @throws \RuntimeException when the agent is not registered
     */
    public function require(string $name): AgentDefinitionDTO
    {
        $definition = $this->get($name);

        if (null === $definition) {
            throw new \RuntimeException(\sprintf('Agent "%s" is not defined.', $name));
        }

        return $definition;
    }

    /**
     * All registered definitions.
     *
     * @return list<AgentDefinitionDTO>
     */
    public function all(): array
    {
        return array_values($this->byName);
    }

    /**
     * Non-fatal diagnostics collected during discovery.
     *
     * @return list<AgentDefinitionDiagnosticDTO>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
