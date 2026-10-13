<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Command\PendingCommand;

final readonly class EnqueueCommandDTO
{
    public function __construct(public PendingCommand $command)
    {
    }
}
