<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Enumerates the closed set of run lifecycle states from queued through terminal (completed, failed, cancelled).
 */
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
