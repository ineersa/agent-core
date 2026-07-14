<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationResultDTO;

/**
 * Child-kind hooks for terminal artifact/presentation during batch supervision.
 */
interface ChildRunBatchLifecycleListenerInterface
{
    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO;
}
