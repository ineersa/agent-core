<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

interface RunStoreInterface
{
    public function get(string $runId): ?RunState;

    public function compareAndSwap(RunState $state, int $expectedVersion): bool;

    /**
     * Finds running runs that have not been updated since the specified timestamp.
     *
     * @return list<RunState>
     */
    public function findRunningStaleBefore(\DateTimeImmutable $updatedBefore): array;
}
