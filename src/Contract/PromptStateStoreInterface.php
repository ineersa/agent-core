<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Stores, fetches, and removes structured prompt state keyed by run identifier.
 */
interface PromptStateStoreInterface
{
    /**
     * Retrieves the state array for a given run ID or returns null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $runId): ?array;

    /**
     * Persists the provided state array under the specified run ID.
     *
     * @param array<string, mixed> $state
     */
    public function save(string $runId, array $state): void;

    public function delete(string $runId): void;
}
