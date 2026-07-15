<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

enum ChildRunBatchExecutionModeEnum: string
{
    case Single = 'single';
    case Parallel = 'parallel';
}
