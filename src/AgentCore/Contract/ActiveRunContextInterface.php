<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Run\RunState;

/** Process-local execution state. Initialization belongs to the owner boundary. */
interface ActiveRunContextInterface
{
    public function createNew(string $runId): RunState;

    public function loadRecovered(RunState $state): void;

    public function requireLoaded(string $runId): RunState;

    public function replaceCurrent(RunState $state): void;

    public function release(string $runId): void;
}
