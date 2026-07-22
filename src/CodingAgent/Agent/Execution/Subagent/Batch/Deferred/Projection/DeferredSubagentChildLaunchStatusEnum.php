<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection;

enum DeferredSubagentChildLaunchStatusEnum: string
{
    case Reserved = 'reserved';
    case Launched = 'launched';
    case Failed = 'failed';
}
