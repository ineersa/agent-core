<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\ForegroundAgentChildRunSupervisor;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentSupervisionResultMapper;
use Ineersa\CodingAgent\Config\AgentsConfig;

/**
 * Stable public façade for foreground subagent tool execution.
 *
 * Single and parallel runs share {@see ForegroundAgentChildRunSupervisor::supervise()} (batch of one vs many).
 */
final class SubagentExecutionService
{
    public function __construct(
        private readonly SubagentLaunchPreparationService $launchPreparation,
        private readonly ForegroundAgentChildRunSupervisor $batchSupervisor,
        private readonly ParallelSubagentExecutionService $parallelExecution,
        private readonly SubagentSupervisionResultMapper $resultMapper,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * @throws ToolCallException
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): string {
        $prepared = $this->launchPreparation->prepareSingle($parentRunId, $agentName, $task);
        $batch = new ChildRunBatchDTO(
            $parentRunId,
            [$prepared],
            $this->agentsConfig->subagentToolTimeoutSeconds,
            ChildRunBatchExecutionModeEnum::Single,
        );
        $result = $this->batchSupervisor->supervise($batch);

        return $this->resultMapper->mapSingle($result);
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function executeParallel(string $parentRunId, array $tasks): string
    {
        return $this->parallelExecution->execute($parentRunId, $tasks);
    }
}
