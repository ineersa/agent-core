<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchService;

/**
 * Stable public façade for subagent tool execution.
 *
 * Single and parallel tool calls defer through the normalized durable batch launch
 * service ({@see DeferredSubagentBatchLaunchService}); completion is delivered
 * asynchronously via the generic deferred-tool runtime.
 */
final class SubagentExecutionService
{
    public function __construct(
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
        return $this->deferredBatchLaunch->launch(
            $parentRunId,
            [new SubagentTaskDTO(agent: $agentName, task: $task)],
            ChildRunBatchExecutionModeEnum::Single,
        );
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
