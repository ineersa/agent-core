<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

enum ChildRunBatchLaunchAbortPhaseEnum: string
{
    case Preparation = 'preparation';
    case RuntimeStart = 'runtime_start';
}
