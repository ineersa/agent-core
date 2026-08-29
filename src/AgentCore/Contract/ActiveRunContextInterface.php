<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Process-local active run context with operational projection persistence.
 */
interface ActiveRunContextInterface
{
    public function stateFor(string $runId): RunState;

    public function remember(RunState $state): void;

    public function invalidate(string $runId): void;

    public function clear(): void;
}
