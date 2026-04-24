<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\PromptState;

interface PromptStateStoreInterface
{
    /**
     * Retrieves prompt state for a given run ID or returns null if not found.
     */
    public function get(string $runId): ?PromptState;

    /**
     * Persists prompt state under the specified run ID.
     */
    public function save(string $runId, PromptState $state): void;

    public function delete(string $runId): void;
}
