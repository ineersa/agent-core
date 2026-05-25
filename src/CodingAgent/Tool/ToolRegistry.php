<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * CodingAgent-owned ToolRegistry implementation.
 *
 * Manages permanent tools (registered at boot, contribute to system prompt)
 * and dynamic tools (per-request lifecycle, excluded from prompt metadata).
 * All snapshots use deterministic ordering.
 */
final class ToolRegistry implements ToolRegistryInterface
{
    /**
     * @var array<string, array{name: string, description: string, parametersJsonSchema: array, handler: mixed, promptLine: string, promptGuidelines: list<string>}>
     */
    private array $permanentTools = [];

    /**
     * @var list<string> Registration-order tracking for permanent tools
     */
    private array $permanentOrder = [];

    /**
     * @var array<string, array{name: string, description: string, parametersJsonSchema: array, handler: mixed}>
     */
    private array $dynamicTools = [];

    /**
     * @var list<string> Registration-order tracking for dynamic tools
     */
    private array $dynamicOrder = [];

    public function registerTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        mixed $handler,
        string $promptLine,
        array $promptGuidelines = [],
    ): void {
        if ('' === $name || '' === $description) {
            throw new \InvalidArgumentException(\sprintf('Tool name and description must be non-empty strings, got name="%s" description="%s".', $name, $description));
        }

        // Idempotent: identical re-registration is a no-op
        if (isset($this->permanentTools[$name])) {
            return;
        }

        $this->permanentTools[$name] = [
            'name' => $name,
            'description' => $description,
            'parametersJsonSchema' => $parametersJsonSchema,
            'handler' => $handler,
            'promptLine' => $promptLine,
            'promptGuidelines' => $promptGuidelines,
        ];
        $this->permanentOrder[] = $name;
    }

    public function addDynamicTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        mixed $handler,
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

        $this->dynamicTools[$name] = [
            'name' => $name,
            'description' => $description,
            'parametersJsonSchema' => $parametersJsonSchema,
            'handler' => $handler,
        ];
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

    public function getDynamicTools(): array
    {
        $result = [];
        foreach ($this->dynamicOrder as $name) {
            $result[] = $this->dynamicTools[$name];
        }

        return $result;
    }

    public function permanentToolLines(): array
    {
        $lines = [];
        $seen = [];

        foreach ($this->permanentOrder as $name) {
            $line = $this->permanentTools[$name]['promptLine'];
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
            foreach ($this->permanentTools[$name]['promptGuidelines'] as $guideline) {
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
}
