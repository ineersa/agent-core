<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunProgressUpdateDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationResultDTO;

/**
 * Child-kind hooks for progress emission and terminal artifact/presentation during batch supervision.
 */
interface ChildRunBatchLifecycleListenerInterface
{
    public function progressSignature(ChildRunProgressUpdateDTO $update): string;

    public function emitProgress(ChildRunProgressUpdateDTO $update): void;

    public function finalizeTerminalOutcome(ChildRunTerminalFinalizationRequestDTO $request): ChildRunTerminalFinalizationResultDTO;
}
