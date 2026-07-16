<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Launch;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchCoordinator;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Deferred\Launch\DeferredAgentChildBatchLaunchFailureReasonEnum;
use Ineersa\CodingAgent\Agent\Execution\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;

final class DeferredForkBatchLaunchService
{
    public function __construct(
        private readonly DeferredForkBatchPreparationService $batchPreparation,
        private readonly DeferredAgentChildBatchLaunchCoordinator $batchLaunchCoordinator,
        private readonly AgentsConfig $agentsConfig,
    ) {
    }

    public function launch(string $parentRunId, ForkLaunchTaskDTO $task): DeferredToolCompletionOutcome
    {
        try {
            return $this->batchLaunchCoordinator->launch(
                $parentRunId,
                [$task],
                ChildRunBatchExecutionModeEnum::Single,
                $this->batchPreparation,
                $this->agentsConfig->subagentToolTimeoutSeconds,
            );
        } catch (DeferredAgentChildBatchLaunchException $e) {
            throw $this->mapLaunchException($e);
        }
    }

    private function mapLaunchException(DeferredAgentChildBatchLaunchException $e): ToolCallException
    {
        return match ($e->reason) {
            DeferredAgentChildBatchLaunchFailureReasonEnum::EmptyTasks => new ToolCallException(
                'The fork tool requires a non-empty task.',
                retryable: false,
            ),
            DeferredAgentChildBatchLaunchFailureReasonEnum::ParentContextMismatch => new ToolCallException(
                'Fork tool requires a valid parent run ID.',
                retryable: false,
            ),
            DeferredAgentChildBatchLaunchFailureReasonEnum::PreviouslyFailed => new ToolCallException(
                'Fork launch previously failed for this tool call.',
                retryable: false,
            ),
            DeferredAgentChildBatchLaunchFailureReasonEnum::PreparationFailed,
            DeferredAgentChildBatchLaunchFailureReasonEnum::RuntimeStartFailed => $this->mapPreparationOrRuntimeFailure($e),
        };
    }

    private function mapPreparationOrRuntimeFailure(DeferredAgentChildBatchLaunchException $e): ToolCallException
    {
        $previous = $e->getPrevious();
        if ($previous instanceof ToolCallException) {
            return $previous;
        }

        return new ToolCallException(
            'Fork launch failed.',
            retryable: false,
            previous: $previous,
        );
    }
}
