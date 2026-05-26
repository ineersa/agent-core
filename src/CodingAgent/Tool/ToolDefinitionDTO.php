<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Immutable snapshot of a single tool registration.
 *
 * Used internally by ToolRegistry for tool metadata storage and exposed
 * via ToolRegistryInterface::activeToolDefinitions() and toolDefinition()
 * for downstream adapters (e.g. RegistryBackedToolbox in TOOLS-R03).
 *
 * The handler property is typed as ToolHandlerInterface for type safety
 * instead of PHP's `callable` pseudo-type, which cannot be used as a
 * property type. Implementations are invokable objects that receive
 * decoded tool call arguments as an associative array.
 */
final readonly class ToolDefinitionDTO
{
    /**
     * @param string               $name                 Model-visible tool name (unique identifier)
     * @param string               $description          Provider-schema description shown to the LLM
     * @param array<string, mixed> $parametersJsonSchema JSON Schema describing tool parameters
     * @param ToolHandlerInterface $handler              Execution handler invoked with decoded arguments
     * @param string               $promptLine           One-line description for the <available_tools> prompt section
     * @param list<string>         $promptGuidelines     Zero or more guideline strings for the prompt guidelines section
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersJsonSchema,
        public readonly ToolHandlerInterface $handler,
        public readonly string $promptLine,
        public readonly array $promptGuidelines = [],
    ) {
    }
}
