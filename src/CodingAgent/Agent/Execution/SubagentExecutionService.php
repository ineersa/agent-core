<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;

/**
 * Stable public façade for foreground subagent tool execution.
 *
 * Single runs defer through {@see DeferredSingleSubagentLaunchService}; parallel runs
 * remain on {@see ParallelSubagentExecutionService} + {@see ForegroundChildRunSupervisor}.
 */
final class SubagentExecutionService
{
    public function __construct(
        private readonly DeferredSingleSubagentLaunchService $deferredSingleLaunch,
        private readonly ParallelSubagentExecutionService $parallelExecution,
    ) {
    }

    /**
     * @throws ToolCallException
     */
    public function execute(
        string $parentRunId,
        string $agentName,
        string $task,
    ): DeferredToolCompletionOutcome {
        return $this->deferredSingleLaunch->launch($parentRunId, $agentName, $task);
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function executeParallel(string $parentRunId, array $tasks): string
    {
        return $this->parallelExecution->execute($parentRunId, $tasks);
    }
}
