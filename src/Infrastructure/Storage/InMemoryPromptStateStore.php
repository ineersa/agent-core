<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\PromptStateStoreInterface;

/**
 * InMemoryPromptStateStore provides an ephemeral, in-memory storage mechanism for persisting prompt state associated with specific agent run IDs. It implements a simple key-value interface using an internal array to map run identifiers to their corresponding state arrays.
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
