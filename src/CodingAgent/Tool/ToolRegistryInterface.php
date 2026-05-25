<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

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
     * @param mixed                $handler              Execution handler (callable or Symfony Tool reference)
     * @param string               $promptLine           Single-line description for <available_tools>
     * @param list<string>         $promptGuidelines     Zero or more guideline strings for <guidelines>
     *
     * @throws \InvalidArgumentException on empty name or description
     */
    public function registerTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        mixed $handler,
        string $promptLine,
        array $promptGuidelines = [],
    ): void;

    /**
     * Add a dynamic tool for the current request/turn.
     *
     * Dynamic tools are never included in system prompt snapshots but
     * are included in active provider-schema and execution-allowlist
     * snapshots.
     *
     * @param string               $name    Model-visible tool name (must not
     *                                      conflict with a permanent tool name)
     * @param string               $description
     * @param array<string, mixed> $parametersJsonSchema
     * @param mixed                $handler
     *
     * @throws \InvalidArgumentException on name conflict with permanent tool
     */
    public function addDynamicTool(
        string $name,
        string $description,
        array $parametersJsonSchema,
        mixed $handler,
    ): void;

    /**
     * Remove a dynamic tool by name. No-op if not found.
     */
    public function removeDynamicTool(string $name): void;

    /**
     * Replace all dynamic tools with the given set.
     *
     * @param list<array{name: string, description: string, parametersJsonSchema: array, handler: mixed}> $tools
     *
     * @throws \InvalidArgumentException on name conflict with permanent tools
     */
    public function setDynamicTools(array $tools): void;

    /**
     * Return all currently registered dynamic tools.
     *
     * @return list<array{name: string, description: string, parametersJsonSchema: array, handler: mixed}>
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
     * Return all active tool names (permanent + dynamic) in deterministic
     * order (permanent first, then dynamic, each in insertion order).
     *
     * @return list<string>
     */
    public function activeToolNames(): array;
}
