<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Tool\DTO\AgentResumeArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

/**
 * Execution handler for the `agent_resume` tool.
 */
final class AgentResumeToolHandler
{
    public const string NAME = 'agent_resume';

    public const string DESCRIPTION_TEMPLATE = 'Continue an existing terminal subagent or fork with a follow-up task, using artifact_id or agent_run_id. By default, wait for completion and return its latest handoff. Set backgrounded=true to continue other work while the child runs; you will be notified when it finishes. Do not poll for completion. Responses over 50,000 characters return artifact references instead. Use agent_retrieve for omitted handoffs or mode=handoff_history for earlier handoffs.';

    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        /** @var ContainerInterface AgentResumeExecutionService is resolved only on invoke. */
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    public function __invoke(
        #[MapToolArguments]
        AgentResumeArgumentsDTO $arguments,
    ): DeferredToolCompletionOutcome {
        return $this->toolRuntime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The agent_resume tool requires an active parent run context.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('agent_resume requires a valid parent run ID.', retryable: false);
            }

            return $this->executionService()->resume($parentRunId, $arguments);
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
