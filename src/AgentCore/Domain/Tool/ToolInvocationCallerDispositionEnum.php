<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/** Caller-wait disposition for one tool invocation. Independent of native execution observation. */
enum ToolInvocationCallerDispositionEnum: string
{
    case ForegroundPending = 'foreground_pending';
    case BackgroundAcknowledged = 'background_acknowledged';
    case ForegroundResultAccepted = 'foreground_result_accepted';
}
