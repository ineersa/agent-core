<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchService;

/**
 * Stable public façade for subagent tool execution.
 *
 * Single and parallel runs defer through durable launch services; completion is
 * delivered asynchronously via the generic deferred-tool runtime.
 */
final class SubagentExecutionService
{
    public function __construct(
        private readonly DeferredSingleSubagentLaunchService $deferredSingleLaunch,
        private readonly DeferredSubagentBatchLaunchService $deferredBatchLaunch,
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
     *
     * @throws ToolCallException
     */
    public function executeParallel(string $parentRunId, array $tasks): DeferredToolCompletionOutcome
    {
        return $this->deferredBatchLaunch->launch($parentRunId, $tasks, ChildRunBatchExecutionModeEnum::Parallel);
    }
}
