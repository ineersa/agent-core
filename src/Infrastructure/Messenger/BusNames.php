<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Messenger;

/**
 * Defines bus name constants for the command, execution, and publisher Messenger buses.
 */
final class BusNames
{
    public const string Command = 'agent.command.bus';
    public const string Execution = 'agent.execution.bus';
    public const string Publisher = 'agent.publisher.bus';
}
