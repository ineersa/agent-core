<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Run\RunState;

interface CommandConsumptionPolicyInterface
{
    public function prepare(RunState $state, PendingCommand $command): ?CommandConsumptionDecisionDTO;
}
