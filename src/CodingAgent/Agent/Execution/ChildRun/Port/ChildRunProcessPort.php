<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Port;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\StartRunInput;

interface ChildRunProcessPort
{
    public function start(StartRunInput $input): void;

    public function cancel(string $childRunId, string $reason): void;

    public function getState(string $childRunId): ?RunState;
}
