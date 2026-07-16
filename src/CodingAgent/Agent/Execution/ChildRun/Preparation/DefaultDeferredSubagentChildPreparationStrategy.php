<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentLaunchPreparationService;

final class DefaultDeferredSubagentChildPreparationStrategy implements DeferredSubagentChildPreparationStrategyInterface
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
    ) {
    }

    public function prepare(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        return $this->launchPreparation->prepareFromDefinition(
            $parentRunId,
            $definition,
            $agentName,
            $task,
            $identity->artifactId,
            $identity->childRunId,
            skipReservation: true,
            identityTemplate: $identity,
        );
    }
}
