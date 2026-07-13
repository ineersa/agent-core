<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

enum ChildRunBatchCompletionKindEnum: string
{
    case AllSucceeded = 'all_succeeded';
    case SingleSucceeded = 'single_succeeded';
    case SingleTimedOut = 'single_timed_out';
    case ParentCancelled = 'parent_cancelled';
    case BatchTimedOut = 'batch_timed_out';
    case PartialFailure = 'partial_failure';
    case LaunchAborted = 'launch_aborted';
}
