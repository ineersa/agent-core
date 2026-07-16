<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;

/**
 * Typed hook for building a single deferred child StartRunInput without duplicating batch lifecycle.
 */
interface DeferredSubagentChildPreparationStrategyInterface
{
    public function prepare(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO;
}
