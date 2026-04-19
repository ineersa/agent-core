<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\PromptStateStoreInterface;

/**
 * HotPromptStateStore provides a lightweight, in-memory cache for transient prompt execution states keyed by run ID. It is designed for high-speed access during active agent runs but does not guarantee persistence across restarts.
 */
final class HotPromptStateStore implements PromptStateStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $states = [];

    /**
     * Retrieves the cached state array for a specific run ID or returns null if not found.
     */
    public function get(string $runId): ?array
    {
        return $this->states[$runId] ?? null;
    }

    /**
     * Stores the provided state array in the cache under the specified run ID.
     */
    public function save(string $runId, array $state): void
    {
        if (null !== $this->get($runId)) {
            $this->delete($runId);
        }

        $normalizedState = $state;
        $updatedAt = new \DateTimeImmutable();
        $normalizedState['updated_at'] = $updatedAt->format(\DATE_ATOM);

        $this->states[$runId] = $normalizedState;
    }

    /**
     * Removes the cached state entry for the specified run ID from the cache.
     */
    public function delete(string $runId): void
    {
        unset($this->states[$runId]);
    }
}
