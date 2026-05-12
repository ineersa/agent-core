<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingHuman = 'waiting_human';
    case Cancelling = 'cancelling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
