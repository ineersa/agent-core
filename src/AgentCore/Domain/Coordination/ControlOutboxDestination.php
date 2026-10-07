<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

/** Closed destinations for durable control-message publication. */
final class ControlOutboxDestination
{
    public const string COMMAND = 'command';
    public const string EXECUTION = 'execution';
}
