<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/** Bounded current-tool lifecycle statuses; never a tool-result history. */
enum RunOperationalToolCallStatusEnum: string
{
    case Pending = 'pending';
    case Running = 'running';
    case WaitingHuman = 'waiting_human';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
