<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Immutable registry holding discovered agent definitions and diagnostics.
 *
 * Definitions are stored by name (deterministic). Disabled definitions
 * are included in the registry so they can be listed and documented,
 * but are excluded from enabled/launchable lookups.
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
     * Look up an enabled definition by name. Throws when the agent is not
     * found or is disabled.
     *
     * @throws \RuntimeException when the agent is not registered or is disabled
     */
    public function requireEnabled(string $name): AgentDefinitionDTO
    {
        $definition = $this->require($name);

        if ($definition->disabled) {
            throw new \RuntimeException(\sprintf('Agent "%s" is disabled.', $name));
        }

        return $definition;
    }

    /**
     * All registered definitions, including disabled ones.
     *
     * @return list<AgentDefinitionDTO>
     */
    public function all(): array
    {
        return array_values($this->byName);
    }

    /**
     * Enabled definitions only (disabled excluded).
     *
     * @return list<AgentDefinitionDTO>
     */
    public function enabled(): array
    {
        return array_values(
            array_filter(
                $this->byName,
                static fn (AgentDefinitionDTO $d): bool => !$d->disabled,
            ),
        );
    }

    /**
     * Disabled definitions only.
     *
     * @return list<AgentDefinitionDTO>
     */
    public function disabled(): array
    {
        return array_values(
            array_filter(
                $this->byName,
                static fn (AgentDefinitionDTO $d): bool => $d->disabled,
            ),
        );
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
