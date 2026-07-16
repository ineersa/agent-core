<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;

final readonly class ForkDeferredChildPreparationStrategy implements DeferredSubagentChildPreparationStrategyInterface
{
    public function __construct(
        private ForkToolPolicyResolver $forkToolPolicyResolver,
        private ForkChildLaunchInputBuilder $launchInputBuilder,
        private ForkLaunchTaskDTO $launchTask,
    ) {
    }

    public function prepare(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        // $definition, $agentName, and $task come from the generic deferred batch seam; fork uses
        // immutable $this->launchTask and resolves tool policy from the parent run via ForkToolPolicyResolver.

        return $this->launchInputBuilder->buildPrepared(
            $identity,
            $this->launchTask,
            $this->forkToolPolicyResolver->resolve($parentRunId),
        );
    }
}
