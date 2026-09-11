<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Immutable snapshot of a single tool registration.
 *
 * Used internally by ToolRegistry for tool metadata storage and exposed
 * via ToolRegistryInterface::activeToolDefinitions() and toolDefinition()
 * for downstream adapters (e.g. RegistryBackedToolbox in TOOLS-R03).
 *
 * The handler is an invokable object (typed as `object` because PHP's
 * callable pseudo-type cannot be used as a property type). Two handler
 * shapes exist:
 * - Typed DTO handlers (built-ins): `__invoke` takes one class-typed parameter.
 *   `parametersJsonSchema` is usually null (schema generated from the DTO).
 *   An explicit flat schema is allowed when native generation cannot match the
 *   required provider shape; resolution/validation still use the DTO path.
 * - Raw-array handlers (MCP, public extension adapters): `__invoke` takes a
 *   builtin `$arguments` array; `parametersJsonSchema` carries the runtime
 *   schema and arguments are passed through as the flat provider map.
 *
 * The executionMode defaults to sequential. Tool authors/providers should
 * set it in their definition() return when non-default behavior is needed.
 * File-mutation tools (write, edit) must always run sequentially.
 *
 * The description is required because dynamic/raw registrations (MCP,
 * extensions) only carry it here. Typed built-in tools reference the same
 * per-tool class constants (NAME / DESCRIPTION or DESCRIPTION_TEMPLATE) from
 * their definition() so the registry description stays canonical for
 * provider metadata.
 */
final readonly class ToolDefinitionDTO
{
    /**
     * @param string                    $name                 Model-visible tool name (unique identifier)
     * @param string                    $description          Provider-schema description shown to the LLM
     * @param array<string, mixed>|null $parametersJsonSchema Flat provider JSON Schema when supplied; null generates from a typed DTO handler. Raw vs typed resolution is decided from the handler signature, not merely from whether this is set.
     * @param object                    $handler              Invokable execution handler
     * @param string                    $promptLine           One-line description for the <available_tools> prompt section
     * @param list<string>              $promptGuidelines     Zero or more guideline strings for the prompt guidelines section
     * @param ToolExecutionMode         $executionMode        Execution mode for this tool (default: Sequential)
     * @param int|null                  $timeoutSeconds       Per-tool cooperative timeout budget in seconds; null means no ambient deadline
     * @param string|null               $extensionOwnerClass  Owning extension FQCN when registered by an extension; null for built-ins
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly object $handler,
        public readonly ?array $parametersJsonSchema = null,
        public readonly string $promptLine = '',
        public readonly array $promptGuidelines = [],
        public readonly ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?string $extensionOwnerClass = null,
    ) {
    }
}
