<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\AgentChildLaunchTaskInterface;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchCoordinator;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;

/**
 * Subagent-specific adapter around the generic durable deferred child batch launch coordinator.
 */
final class DeferredSubagentBatchLaunchService
{
    public function __construct(
        private readonly DeferredSubagentBatchPreparationService $batchPreparation,
        private readonly DeferredAgentChildBatchLaunchCoordinator $batchLaunchCoordinator,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    /**
     * @param list<SubagentTaskDTO> $tasks
     */
    public function launch(
        string $parentRunId,
        array $tasks,
        ChildRunBatchExecutionModeEnum $executionMode,
    ): DeferredToolCompletionOutcome {
        if (ChildRunBatchExecutionModeEnum::Single === $executionMode && 1 !== \count($tasks)) {
            throw new ToolCallException('Single subagent requires exactly one task.', retryable: false);
        }

        if ([] === $tasks) {
            throw new ToolCallException('Deferred subagent batch launch requires at least one task.', retryable: false);
        }

        $maxAgents = $this->agentsConfig->maxAgents;
        $taskCount = \count($tasks);
        if ($taskCount > $maxAgents) {
            throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, $taskCount), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
        }

        /** @var list<AgentChildLaunchTaskInterface> $launchTasks */
        $launchTasks = $tasks;

        return $this->batchLaunchCoordinator->launch(
            $parentRunId,
            $launchTasks,
            $executionMode,
            $this->batchPreparation,
            'Subagent batch launch failed.',
            'Subagent batch launch previously failed for this tool call.',
        );
    }
}
