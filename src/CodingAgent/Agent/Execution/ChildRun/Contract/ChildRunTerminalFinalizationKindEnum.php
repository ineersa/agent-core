<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

enum ChildRunTerminalFinalizationKindEnum: string
{
    case PersistOnly = 'persist_only';
    case SingleCompleted = 'single_completed';
    case SingleFailed = 'single_failed';
    case SingleChildCancelled = 'single_child_cancelled';
    case SingleTimeout = 'single_timeout';
    case ParallelRunTerminal = 'parallel_run_terminal';
}
