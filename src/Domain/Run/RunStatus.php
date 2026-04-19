<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Defines the immutable lifecycle states for a Run entity within the AgentCore domain. This enum enforces type-safe state transitions and prevents invalid status updates by restricting values to a closed set of operational phases.
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
