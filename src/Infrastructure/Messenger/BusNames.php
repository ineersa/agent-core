<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Messenger;

/**
 * This class provides a centralized registry of named constants for identifying different message bus instances within the application. It serves as a configuration boundary to ensure consistent bus selection across the infrastructure layer.
 */
final class BusNames
{
    public const string Command = 'agent.command.bus';
    public const string Execution = 'agent.execution.bus';
    public const string Publisher = 'agent.publisher.bus';
}
