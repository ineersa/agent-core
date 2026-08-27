<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * Process-local run_control ownership of active full run context.
 */
interface ActiveRunContextInterface
{
    public function stateFor(string $runId): RunState;

    public function remember(RunState $state): void;

    public function invalidate(string $runId): void;

    public function clear(): void;
}
