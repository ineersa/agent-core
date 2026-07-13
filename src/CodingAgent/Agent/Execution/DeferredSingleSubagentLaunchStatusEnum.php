<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

enum DeferredSingleSubagentLaunchStatusEnum: string
{
    case Reserved = 'reserved';
    case Launched = 'launched';
    case Failed = 'failed';
}
