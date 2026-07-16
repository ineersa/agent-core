<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;

final readonly class ForkDeferredChildPreparationStrategy implements DeferredSubagentChildPreparationStrategyInterface
{
    public function __construct(
        private ForkLaunchPreparationService $launchPreparation,
        private ForkChildLaunchInputBuilder $launchInputBuilder,
        private ForkLaunchTaskDTO $launchTask,
    ) {
    }

    public function prepare(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        return $this->launchInputBuilder->buildPrepared(
            $identity,
            $this->launchTask,
            $this->launchPreparation->resolveToolPolicy($parentRunId),
        );
    }
}
