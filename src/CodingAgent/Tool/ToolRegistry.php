<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * CodingAgent-owned ToolRegistry implementation.
 *
 * Manages permanent tools (registered at boot, contribute to system prompt)
 * and dynamic tools (per-request lifecycle, excluded from prompt metadata).
 * All snapshots use deterministic ordering.
 *
 * Internal storage uses ToolDefinitionDTO for permanent tools, providing
 * type-safe handler access and structured definition lookup. Dynamic tools
 * also store a ToolDefinitionDTO (with empty prompt metadata).
 *
 * Built-in providers are injected by Symfony as a tagged iterator and seeded
 * during registry construction so the tool subsystem owns registration instead
 * of Kernel boot hooks.
 *
 * Execution mode is set at registration time and defaults to Sequential.
 * File-mutation tools (write, edit) should set it explicitly to Sequential
 * (the default) in their HatfieldToolProviderInterface::definition().
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /**
     * @var array<string, ToolDefinitionDTO>
     */
    private array $permanentTools = [];

    /**
     * @var list<string> Registration-order tracking for permanent tools
     */
    private array $permanentOrder = [];

    /**
     * @var array<string, ToolDefinitionDTO>
     */
    private array $dynamicTools = [];

    /**
     * @var list<string> Registration-order tracking for dynamic tools
     */
    private array $dynamicOrder = [];

    /**
     * @var array<string, true> excluded tool names (denylist)
     */
    private array $excludedNames = [];

    /**
     * @var array<string, true>|null Allowed tool names (allowlist).
     *                               null means no allowlist (all tools visible).
     */
    private ?array $allowedNames = null;

    /**
     * @param iterable<HatfieldToolProviderInterface> $providers Tagged built-in tool providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $definition = $provider->definition();

            $this->registerTool(
                name: $definition->name,
                description: $definition->description,
                parametersJsonSchema: $definition->parametersJsonSchema,
                handler: $definition->handler,
                promptLine: $definition->promptLine,
                promptGuidelines: $definition->promptGuidelines,
                executionMode: $definition->executionMode,
            );
        }
    }

    public function registerTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        ToolHandlerInterface $handler,
        string $promptLine,
        array $promptGuidelines = [],
        ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
    ): void {
        if ('' === $name || '' === $description) {
            throw new \InvalidArgumentException(\sprintf('Tool name and description must be non-empty strings, got name="%s" description="%s".', $name, $description));
        }

        // Idempotent: identical re-registration is a no-op
        if (isset($this->permanentTools[$name])) {
            return;
        }

        $this->permanentTools[$name] = new ToolDefinitionDTO(
            name: $name,
            description: $description,
            parametersJsonSchema: $parametersJsonSchema,
            handler: $handler,
            promptLine: $promptLine,
            promptGuidelines: $promptGuidelines,
            executionMode: $executionMode,
        );
        $this->permanentOrder[] = $name;
    }

    public function addDynamicTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        ToolHandlerInterface $handler,
        ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
    ): void {
        if ('' === $name || '' === $description) {
            throw new \InvalidArgumentException(\sprintf('Dynamic tool name and description must be non-empty strings, got name="%s" description="%s".', $name, $description));
        }

        if (isset($this->permanentTools[$name])) {
            throw new \InvalidArgumentException(\sprintf('Cannot register dynamic tool "%s": a permanent tool with the same name already exists.', $name));
        }

        // Replace if already a dynamic tool (update in place)
        if (!isset($this->dynamicTools[$name])) {
            $this->dynamicOrder[] = $name;
        }

        $this->dynamicTools[$name] = new ToolDefinitionDTO(
            name: $name,
            description: $description,
            parametersJsonSchema: $parametersJsonSchema,
            handler: $handler,
            promptLine: '',  // dynamic tools have no prompt metadata
            promptGuidelines: [],
            executionMode: $executionMode,
        );
    }

    public function removeDynamicTool(string $name): void
    {
        if (!isset($this->dynamicTools[$name])) {
            return;
        }

        unset($this->dynamicTools[$name]);
        $this->dynamicOrder = array_values(array_filter(
            $this->dynamicOrder,
            static fn (string $existing): bool => $existing !== $name,
        ));
    }

    /**
     * @param list<array{name: string, description: string, parametersJsonSchema: array<string, mixed>, handler: mixed}> $tools
     */
    public function setDynamicTools(array $tools): void
    {
        $this->dynamicTools = [];
        $this->dynamicOrder = [];

        foreach ($tools as $tool) {
            $this->addDynamicTool(
                $tool['name'],
                $tool['description'],
                $tool['parametersJsonSchema'],
                $tool['handler'],
            );
        }
    }

    /**
     * @return list<array{name: string, description: string, parametersJsonSchema: array<string, mixed>, handler: mixed}>
     */
    public function getDynamicTools(): array
    {
        $result = [];
        foreach ($this->dynamicOrder as $name) {
            $dto = $this->dynamicTools[$name];
            $result[] = [
                'name' => $dto->name,
                'description' => $dto->description,
                'parametersJsonSchema' => $dto->parametersJsonSchema,
                'handler' => $dto->handler,
            ];
        }

        return $result;
    }

    public function permanentToolLines(): array
    {
        $lines = [];
        $seen = [];

        foreach ($this->permanentOrder as $name) {
            if (!$this->isToolVisible($name)) {
                continue;
            }
            $line = $this->permanentTools[$name]->promptLine;
            if (!isset($seen[$line])) {
                $seen[$line] = true;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public function permanentGuidelines(): array
    {
        $guidelines = [];
        $seen = [];

        foreach ($this->permanentOrder as $name) {
            if (!$this->isToolVisible($name)) {
                continue;
            }
            foreach ($this->permanentTools[$name]->promptGuidelines as $guideline) {
                if (!isset($seen[$guideline])) {
                    $seen[$guideline] = true;
                    $guidelines[] = $guideline;
                }
            }
        }

        return $guidelines;
    }

    public function permanentToolLinesForNames(array $names): array
    {
        $requested = $this->normalizeRequestedPermanentNames($names);
        if ([] === $requested) {
            return [];
        }

        $lines = [];
        $seen = [];

        foreach ($this->permanentOrder as $name) {
            if (!isset($requested[$name]) || !$this->isToolVisible($name)) {
                continue;
            }

            $line = trim($this->permanentTools[$name]->promptLine);
            if ('' === $line || isset($seen[$line])) {
                continue;
            }

            $seen[$line] = true;
            $lines[] = $line;
        }

        return $lines;
    }

    public function permanentGuidelinesForNames(array $names): array
    {
        $requested = $this->normalizeRequestedPermanentNames($names);
        if ([] === $requested) {
            return [];
        }

        $guidelines = [];
        $seen = [];

        foreach ($this->permanentOrder as $name) {
            if (!isset($requested[$name]) || !$this->isToolVisible($name)) {
                continue;
            }

            foreach ($this->permanentTools[$name]->promptGuidelines as $guideline) {
                $guideline = trim($guideline);
                if ('' === $guideline || isset($seen[$guideline])) {
                    continue;
                }

                $seen[$guideline] = true;
                $guidelines[] = $guideline;
            }
        }

        return $guidelines;
    }

    public function setAllowedToolNames(array $names): void
    {
        $allowed = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            if (!$this->isKnownToolName($name)) {
                throw new \InvalidArgumentException(\sprintf('Unknown tool name in allowlist: "%s".', $name));
            }
            $allowed[$name] = true;
        }

        // Empty or whitespace-only input clears the allowlist.
        $this->allowedNames = [] === $allowed ? null : $allowed;
    }

    public function setExcludedToolNames(array $names): void
    {
        $excluded = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }
            if (!$this->isKnownToolName($name)) {
                throw new \InvalidArgumentException(\sprintf('Unknown tool name in exclusions: "%s".', $name));
            }
            $excluded[$name] = true;
        }

        $this->excludedNames = $excluded;
    }

    public function excludedToolNames(): array
    {
        return array_keys($this->excludedNames);
    }

    /**
     * @return list<string>
     */
    public function activeToolNames(): array
    {
        return [
            ...array_values(array_filter(
                $this->permanentOrder,
                fn (string $name): bool => $this->isToolVisible($name),
            )),
            ...array_values(array_filter(
                $this->dynamicOrder,
                fn (string $name): bool => $this->isToolVisible($name),
            )),
        ];
    }

    public function activeToolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->permanentOrder as $name) {
            if ($this->isToolVisible($name)) {
                $definitions[] = $this->permanentTools[$name];
            }
        }

        foreach ($this->dynamicOrder as $name) {
            if ($this->isToolVisible($name)) {
                $definitions[] = $this->dynamicTools[$name];
            }
        }

        return $definitions;
    }

    /**
     * Look up a single tool definition by name.
     *
     * Returns null for registered tools that are excluded or not in the
     * allowlist — not just for unknown names.  This prevents execution of
     * non-visible tools through the registry.
     *
     * @see ToolRegistryInterface::toolDefinition()
     */
    public function toolDefinition(string $name): ?ToolDefinitionDTO
    {
        if (!$this->isToolVisible($name)) {
            return null;
        }

        if (isset($this->permanentTools[$name])) {
            return $this->permanentTools[$name];
        }

        if (isset($this->dynamicTools[$name])) {
            return $this->dynamicTools[$name];
        }

        return null;
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, true> permanent tool names requested at most once each
     */
    private function normalizeRequestedPermanentNames(array $names): array
    {
        $requested = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ('' === $name || !isset($this->permanentTools[$name])) {
                continue;
            }
            $requested[$name] = true;
        }

        return $requested;
    }

    /**
     * Returns true if the given name is a known permanent or dynamic tool.
     */
    private function isKnownToolName(string $name): bool
    {
        return isset($this->permanentTools[$name]) || isset($this->dynamicTools[$name]);
    }

    /**
     * Returns true if the tool with the given name passes the active
     * allowlist/denylist filters.
     */
    private function isToolVisible(string $name): bool
    {
        if (isset($this->excludedNames[$name])) {
            return false;
        }

        if (null !== $this->allowedNames && !isset($this->allowedNames[$name])) {
            return false;
        }

        return true;
    }
}
