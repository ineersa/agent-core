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
            foreach ($this->permanentTools[$name]->promptGuidelines as $guideline) {
                if (!isset($seen[$guideline])) {
                    $seen[$guideline] = true;
                    $guidelines[] = $guideline;
                }
            }
        }

        return $guidelines;
    }

    public function activeToolNames(): array
    {
        // Permanent tools first (registration order), then dynamic tools
        return [
            ...$this->permanentOrder,
            ...$this->dynamicOrder,
        ];
    }

    public function activeToolDefinitions(): array
    {
        $definitions = [];

        foreach ($this->permanentOrder as $name) {
            $definitions[] = $this->permanentTools[$name];
        }

        foreach ($this->dynamicOrder as $name) {
            $definitions[] = $this->dynamicTools[$name];
        }

        return $definitions;
    }

    public function toolDefinition(string $name): ?ToolDefinitionDTO
    {
        if (isset($this->permanentTools[$name])) {
            return $this->permanentTools[$name];
        }

        if (isset($this->dynamicTools[$name])) {
            return $this->dynamicTools[$name];
        }

        return null;
    }
}
