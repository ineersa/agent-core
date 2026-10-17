<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;

interface ChildRunBatchLifecycleListenerInterface
{
    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): RetainedNativeHandoffGeneration;
}
