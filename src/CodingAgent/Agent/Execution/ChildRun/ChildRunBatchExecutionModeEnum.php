<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

enum ChildRunBatchExecutionModeEnum: string
{
    case Single = 'single';
    case Parallel = 'parallel';
}
