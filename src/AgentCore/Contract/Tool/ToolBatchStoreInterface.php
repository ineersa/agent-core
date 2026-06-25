<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Durable store for tool batch execution state.
 *
 * Allows per-run/per-turn/per-step batch state to survive consumer process
 * restarts and coordinate across multiple llm/tool Messenger consumers.
 *
 * Implementations MUST serialize/deserialize the following batch state shape:
 *
 *   expected_order: array<string, int>      — toolCallId => orderIndex
 *   call_data:     array<string, array>     — toolCallId => serialized call fields
 *   pending_queue: list<string>             — ordered toolCallIds remaining
 *   in_flight:     array<string, true>      — toolCallId => true (currently executing)
 *   result_data:   array<string, array>     — toolCallId => serialized result fields
 *   finalized:     bool                     — true when all calls collected
 *   max_parallelism: int
 */
interface ToolBatchStoreInterface
{
    /**
     * Load batch state for a specific run/turn/step.
     *
     * @return array<string, mixed>|null The batch state array, or null if not found
     */
    public function load(string $runId, int $turnNo, string $stepId): ?array;

    /**
     * Save/update batch state for a specific run/turn/step.
     *
     * @param array<string, mixed> $batchState The batch state array
     */
    public function save(string $runId, int $turnNo, string $stepId, array $batchState): void;

    /**
     * Remove batch state for a specific run/turn/step.
     */
    public function delete(string $runId, int $turnNo, string $stepId): void;

    /**
     * Atomically load batch state, apply a callback, and persist when requested.
     *
     * Implementations MUST serialize concurrent updates for the same
     * (runId, turnNo, stepId) so parallel tool result workers cannot
     * lose updates via read-modify-write races.
     *
     * @param callable(array<string, mixed>|null): ToolBatchStoreMutation $callback
     */
    public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed;
}
