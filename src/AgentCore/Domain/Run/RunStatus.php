<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Compacting = 'compacting';
    case WaitingHuman = 'waiting_human';
    case Cancelling = 'cancelling';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Queued, self::Running, self::Compacting, self::WaitingHuman, self::Cancelling => true,
            default => false,
        };
    }
}
