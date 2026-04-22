<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\PromptStateStoreInterface;

/**
 * Stores prompt state per run ID in an ephemeral in-memory array.
 */
final class InMemoryPromptStateStore implements PromptStateStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $states = [];

    public function get(string $runId): ?array
    {
        return $this->states[$runId] ?? null;
    }

    public function save(string $runId, array $state): void
    {
        $this->states[$runId] = $state;
    }

    public function delete(string $runId): void
    {
        unset($this->states[$runId]);
    }
}
