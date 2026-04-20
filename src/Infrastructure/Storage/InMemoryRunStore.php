<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * InMemoryRunStore provides an in-memory implementation of the RunState persistence layer, designed for testing or ephemeral runtime contexts. It manages the lifecycle of agent run states using versioned optimistic locking to ensure consistency during concurrent updates.
 */
final class InMemoryRunStore implements RunStoreInterface
{
    /** @var array<string, RunState> */
    private array $states = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $updatedAtByRun = [];

    public function get(string $runId): ?RunState
    {
        return $this->states[$runId] ?? null;
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        $currentState = $this->states[$state->runId] ?? null;
        $currentVersion = null === $currentState ? 0 : $currentState->version;

        if ($currentVersion !== $expectedVersion) {
            return false;
        }

        $this->states[$state->runId] = $state;
        $this->updatedAtByRun[$state->runId] = new \DateTimeImmutable();

        return true;
    }

    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array
    {
        $stale = [];

        foreach ($this->states as $runId => $state) {
            if (\Ineersa\AgentCore\Domain\Run\RunStatus::Running !== $state->status) {
                continue;
            }

            $updatedAt = $this->updatedAtByRun[$runId] ?? null;
            if (null === $updatedAt || $updatedAt > $updatedBefore) {
                continue;
            }

            $stale[] = $state;
        }

        return $stale;
    }
}
