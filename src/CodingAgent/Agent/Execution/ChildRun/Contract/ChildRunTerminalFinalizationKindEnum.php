<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

enum ChildRunTerminalFinalizationKindEnum: string
{
    case PersistOnly = 'persist_only';
}
