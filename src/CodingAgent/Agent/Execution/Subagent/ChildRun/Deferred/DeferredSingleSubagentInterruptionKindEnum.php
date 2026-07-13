<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

enum DeferredSingleSubagentInterruptionKindEnum: string
{
    case Timeout = 'timeout';
    case ParentCancelled = 'parent_cancelled';
}
