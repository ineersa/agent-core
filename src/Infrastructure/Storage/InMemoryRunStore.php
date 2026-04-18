<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Run\RunState;

final class InMemoryRunStore implements RunStoreInterface
{
    /** @var array<string, RunState> */
    private array $states = [];

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

        return true;
    }
}
