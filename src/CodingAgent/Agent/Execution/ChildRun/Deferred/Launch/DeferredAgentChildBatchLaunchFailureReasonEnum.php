<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch;

enum DeferredAgentChildBatchLaunchFailureReasonEnum: string
{
    case EmptyTasks = 'empty_tasks';
    case ParentContextMismatch = 'parent_context_mismatch';
    case PreviouslyFailed = 'previously_failed';
    case PreparationFailed = 'preparation_failed';
    case RuntimeStartFailed = 'runtime_start_failed';
}
