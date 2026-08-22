<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeArgumentsDTO;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;

/**
 * Execution handler for the `agent_resume` tool.
 */
final class AgentResumeToolHandler
{
    public const string NAME = 'agent_resume';

    public const string DESCRIPTION_TEMPLATE = 'Continue an existing terminal subagent by artifact_id (or agent_run_id) with a follow-up task. Single mode uses artifact_id/task. Parallel mode uses tasks with up to %d resumes per call (agents.max_agents). Blocks until resumed children finish. Single-mode results include the full latest handoff inline; parallel results are bounded summaries — use agent_retrieve for complete parallel handoffs or older archived handoffs.';

    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        /** @var ContainerInterface AgentResumeExecutionService is resolved only on invoke. */
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    public function __invoke(AgentResumeArgumentsDTO $arguments): DeferredToolCompletionOutcome
    {
        return $this->toolRuntime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The agent_resume tool requires an active parent run context.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('agent_resume requires a valid parent run ID.', retryable: false);
            }

            if ($arguments->isParallelMode()) {
                return $this->executionService()->resume(
                    $parentRunId,
                    $arguments->parallelTasks(),
                    ChildRunBatchExecutionModeEnum::Parallel,
                );
            }

            $task = new AgentResumeTaskDTO(
                artifact_id: $arguments->artifact_id,
                task: $arguments->task,
                agent_run_id: $arguments->agent_run_id,
            );

            return $this->executionService()->resume(
                $parentRunId,
                [$task],
                ChildRunBatchExecutionModeEnum::Single,
            );
        });
    }

    private function executionService(): AgentResumeExecutionService
    {
        if (!$this->executionServiceLocator->has(self::EXECUTION_SERVICE_LOCATOR_KEY)) {
            throw new \LogicException('agent_resume execution service is not registered in the tool locator.');
        }

        $service = $this->executionServiceLocator->get(self::EXECUTION_SERVICE_LOCATOR_KEY);
        if (!$service instanceof AgentResumeExecutionService) {
            throw new \LogicException(\sprintf('agent_resume tool locator entry must be AgentResumeExecutionService, got %s.', get_debug_type($service)));
        }

        return $service;
    }
}
