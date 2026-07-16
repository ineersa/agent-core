<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\PreparedAgentChildRunDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Preparation\DeferredSubagentChildPreparationStrategyInterface;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;

final class ForkDeferredChildPreparationStrategy implements DeferredSubagentChildPreparationStrategyInterface
{
    private ?ForkLaunchTaskDTO $launchTask = null;

    public function __construct(
        private readonly ForkLaunchPreparationService $launchPreparation,
        private readonly ForkChildLaunchInputBuilder $launchInputBuilder,
    ) {
    }

    public function configureLaunch(string $task, ?string $modelOverride, ?string $reasoningOverride): void
    {
        $this->launchTask = new ForkLaunchTaskDTO($task, $modelOverride, $reasoningOverride);
    }

    public function prepare(
        string $parentRunId,
        ChildRunIdentityDTO $identity,
        AgentDefinitionDTO $definition,
        string $agentName,
        string $task,
    ): PreparedAgentChildRunDTO {
        if (null === $this->launchTask) {
            throw new ToolCallException('Fork launch task was not configured.', retryable: false);
        }

        return $this->launchInputBuilder->buildPrepared(
            $identity,
            $this->launchTask,
            $this->launchPreparation->resolveToolPolicy($parentRunId),
        );
    }
}
