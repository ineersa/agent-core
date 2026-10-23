<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

enum CodeModeInterruptionReasonEnum: string
{
    case Cancelled = 'cancelled';
    case HistoryDiscarded = 'history_discarded';
    case ControllerRestarted = 'controller_restarted';
    case ControllerShutdown = 'controller_shutdown';
}
