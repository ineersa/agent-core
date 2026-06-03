<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

/**
 * @internal
 */
enum CommandApplicationBoundary: string
{
    case TurnStart = 'turn_start';
    case StopBoundary = 'stop_boundary';
}
