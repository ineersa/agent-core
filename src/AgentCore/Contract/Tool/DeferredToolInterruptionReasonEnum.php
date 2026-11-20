<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

enum DeferredToolInterruptionReasonEnum: string
{
    case Stopped = 'stopped';
    case Cancelled = 'cancelled';
    case HistoryDiscarded = 'history_discarded';
    case ControllerLost = 'controller_lost';

    public function preventsContinuation(): bool
    {
        return self::ControllerLost !== $this;
    }
}
