<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Defines the contract for persisting and retrieving agent execution state keyed by run identifiers. It provides a minimal interface for storing, fetching, and removing structured state arrays associated with specific agent runs.
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
