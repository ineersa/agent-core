<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * CodingAgent-owned ToolRegistry contract.
 *
 * Separates permanent tool registration (which contributes to the stable
 * system prompt) from dynamic tools (which can be changed per-request and
 * never appear in the system prompt). Provider tool schemas and execution
 * allowlists are derived from the same active snapshot.
 *
 * Permanent tools use deterministic registration-order deduplication for
 * both prompt lines and guidelines. Duplicate permanent names are
 * idempotent (identical re-registration is a no-op); conflicting
 * permanent/dynamic name collisions are rejected with a documented
 * exception.
 *
 * Tool definitions are stored as ToolDefinitionDTO internally and
 * exposed via activeToolDefinitions() / toolDefinition() for downstream
 * adapters (e.g. RegistryBackedToolbox in TOOLS-R03).
 *
 * The executionMode defaults to Sequential. Tool authors set it at
 * registration time; the corresponding value flows through ActiveToolSet
 * to AgentCore's scheduling layer.
 */
interface ToolRegistryInterface
{
    /**
     * Register a permanent tool.
     *
     * Permanent tools are active by default, included in provider-schema
     * snapshots, and contribute prompt description lines and guideline
     * strings to the stable system prompt.
     *
     * Identical re-registration (same name, same description, same
     * handler) is idempotent. Re-registration with different metadata
     * for an existing name is a no-op (first wins).
     *
     * @param string               $name                 Model-visible tool name
     * @param string               $description          Provider-schema description
     * @param array<string, mixed> $parametersJsonSchema JSON Schema for tool parameters
     * @param ToolHandlerInterface $handler              Typed execution handler
     * @param string               $promptLine           Single-line description for <available_tools>
     * @param list<string>         $promptGuidelines     Zero or more guideline strings for <guidelines>
     * @param ToolExecutionMode    $executionMode        Execution mode (default: Sequential)
     *
     * @throws \InvalidArgumentException on empty name or description
     */
    public function registerTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        ToolHandlerInterface $handler,
        string $promptLine,
        array $promptGuidelines = [],
        ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
    ): void;

    /**
     * Add a dynamic tool for the current request/turn.
     *
     * Dynamic tools are never included in system prompt snapshots but
     * are included in active provider-schema and execution-allowlist
     * snapshots.
     *
     * @param string               $name                 Model-visible tool name (must not
     *                                                   conflict with a permanent tool name)
     * @param string               $description          Provider-schema description
     * @param array<string, mixed> $parametersJsonSchema JSON Schema for tool parameters
     * @param ToolHandlerInterface $handler              Typed execution handler
     * @param ToolExecutionMode    $executionMode        Execution mode (default: Sequential)
     *
     * @throws \InvalidArgumentException on name conflict with permanent tool
     */
    public function addDynamicTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        ToolHandlerInterface $handler,
        ToolExecutionMode $executionMode = ToolExecutionMode::Sequential,
    ): void;

    /**
     * Remove a dynamic tool by name. No-op if not found.
     */
    public function removeDynamicTool(string $name): void;

    /**
     * Replace all dynamic tools with the given set.
     *
     * @param list<array{name: string, description: string, parametersJsonSchema: array<string, mixed>, handler: mixed}> $tools
     *
     * @throws \InvalidArgumentException on name conflict with permanent tools
     */
    public function setDynamicTools(array $tools): void;

    /**
     * Return all currently registered dynamic tools.
     *
     * @return list<array{name: string, description: string, parametersJsonSchema: array<string, mixed>, handler: mixed}>
     */
    public function getDynamicTools(): array;

    /**
     * Return deduped permanent tool prompt lines in registration order.
     *
     * @return list<string>
     */
    public function permanentToolLines(): array;

    /**
     * Return deduped permanent tool prompt guidelines in registration order
     * (first occurrence determines position for duplicates).
     *
     * @return list<string>
     */
    public function permanentGuidelines(): array;

    /**
     * Return deduped permanent tool prompt lines for the requested tool names only.
     *
     * Iterates permanent registration order. Only names that are registered permanent
     * tools and pass the current allowlist/denylist visibility filter contribute lines.
     * Dynamic/MCP tools and unknown names are ignored (no synthesized text from
     * provider descriptions). Empty promptLine values are skipped.
     *
     * @param list<string> $names Requested tool names (e.g. child runtime allowlist)
     *
     * @return list<string>
     */
    public function permanentToolLinesForNames(array $names): array;

    /**
     * Return deduped permanent tool prompt guidelines for the requested tool names only.
     *
     * Same structural rules as {@see permanentToolLinesForNames()}.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public function permanentGuidelinesForNames(array $names): array;

    /**
     * Set the allowed tool names (allowlist).
     *
     * When non-empty, only tools whose name is in this set are visible
     * through activeToolDefinitions(), activeToolNames(), permanentToolLines(),
     * and permanentGuidelines(). When empty (default), all tools are visible.
     *
     * Combined with setExcludedToolNames(), final visibility is:
     *   (empty allowlist OR name in allowlist) AND (name NOT in exclusions).
     *
     * Unknown tool names are rejected with \InvalidArgumentException.
     *
     * @param list<string> $names
     *
     * @throws \InvalidArgumentException if any name is not a registered permanent or dynamic tool
     */
    public function setAllowedToolNames(array $names): void;

    /**
     * Set the excluded tool names (denylist).
     *
     * Excluded tools are hidden from activeToolDefinitions(), activeToolNames(),
     * permanentToolLines(), and permanentGuidelines().
     *
     * Unknown tool names are rejected with \InvalidArgumentException.
     *
     * @param list<string> $names
     *
     * @throws \InvalidArgumentException if any name is not a registered permanent or dynamic tool
     */
    public function setExcludedToolNames(array $names): void;

    /**
     * Get the current excluded tool names.
     *
     * @return list<string>
     */
    public function excludedToolNames(): array;

    /**
     * Return all active tool names (permanent + dynamic) in deterministic
     * order (permanent first, then dynamic, each in insertion order).
     *
     * @return list<string>
     */
    public function activeToolNames(): array;

    /**
     * Return active tool definitions as immutable value objects.
     *
     * Permanent tools first (registration order), then dynamic tools
     * (insertion order). ToolDefinitionDTOs are readonly immutable value
     * objects — callers receive the canonical instance and cannot mutate
     * registry state.
     *
     * @return list<ToolDefinitionDTO>
     */
    public function activeToolDefinitions(): array;

    /**
     * Look up a single tool definition by name.
     *
     * Searches permanent tools first, then dynamic tools. Returns the
     * canonical immutable ToolDefinitionDTO directly, not a copy.
     *
     * The visibility filter (allowlist/denylist) is applied: returns
     * null for registered but excluded or allowlist-filtered tools,
     * not just for unknown names.  This prevents execution of
     * non-visible tools through the registry.
     *
     * @return ToolDefinitionDTO|null The definition, or null if the tool
     *                                is not registered or is excluded by
     *                                the current visibility filter
     */
    public function toolDefinition(string $name): ?ToolDefinitionDTO;
}
