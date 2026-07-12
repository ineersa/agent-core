<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelLaunchFailureFinalizer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentSupervisionResultMapper;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Symfony\Component\Uid\Uuid;

final class ParallelSubagentExecutionService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly ForegroundAgentChildRunSupervisor $batchSupervisor,
        private readonly SubagentParallelLaunchFailureFinalizer $launchFailureFinalizer,
        private readonly SubagentSupervisionResultMapper $resultMapper,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function execute(string $parentRunId, array $tasks): string
    {
        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }

        $this->launchPreparation->assertDepthAllowed($parentRunId);

        /** @var list<array{index:int,agentName:string,task:string,artifactId:string,agentRunId:string,definition:AgentDefinitionDTO}> $launches */
        $launches = [];
        foreach ($tasks as $index => $taskDto) {
            $agentName = $taskDto->trimmedAgent();
            $taskText = $taskDto->trimmedTask();
            $definition = $this->launchPreparation->requireParallelDefinition($agentName);
            $launches[] = [
                'index' => $index + 1,
                'agentName' => $agentName,
                'task' => $taskText,
                'artifactId' => 'agent_'.bin2hex(random_bytes(8)),
                'agentRunId' => Uuid::v4()->toRfc4122(),
                'definition' => $definition,
            ];
        }

        $identities = [];
        foreach ($launches as $launch) {
            $identities[] = new ChildRunIdentityDTO(
                parentRunId: $parentRunId,
                childRunId: $launch['agentRunId'],
                artifactId: $launch['artifactId'],
                displayName: $launch['agentName'],
                taskSummary: $launch['task'],
                definitionModel: $launch['definition']->model,
                artifactKind: AgentArtifactKindEnum::Subagent,
                batchIndex: $launch['index'],
            );
        }

        $preparedChildren = [];
        try {
            foreach ($launches as $index => $launch) {
                $identity = $identities[$index];
                $this->launchPreparation->reserveIdentity($identity);
                $preparedChildren[] = $this->launchPreparation->prepareFromDefinition(
                    $parentRunId,
                    $launch['definition'],
                    $launch['agentName'],
                    $launch['task'],
                    $launch['artifactId'],
                    $launch['agentRunId'],
                    artifactReservedPending: true,
                    identityTemplate: $identity,
                );
            }
        } catch (\Throwable $e) {
            $aborted = $this->launchFailureFinalizer->finalize($parentRunId, $identities, $e);

            return $this->resultMapper->mapParallel($aborted);
        }

        $batch = new ChildRunBatchDTO($parentRunId, $preparedChildren, $this->agentsConfig->subagentToolTimeoutSeconds, ChildRunBatchExecutionModeEnum::Parallel);
        $result = $this->batchSupervisor->supervise($batch);

        return $this->resultMapper->mapParallel($result);
    }
}
