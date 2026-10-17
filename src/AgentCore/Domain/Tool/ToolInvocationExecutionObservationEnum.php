<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Observed native execution state for one tool invocation. Independent of caller disposition. */
enum ToolInvocationExecutionObservationEnum: string
{
    case Active = 'active';
    case CancelRequested = 'cancel_requested';
    case Terminal = 'terminal';
    case Unknown = 'unknown';
}
