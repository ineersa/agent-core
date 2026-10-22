<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Tool\DTO\SubagentArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

/**
 * Execution handler for the `subagent` tool.
 *
 * Resolves SubagentExecutionService only at invocation time via a narrow
 * service locator so ToolRegistry can register the tool definition without
 * constructing the heavy subagent execution graph at container compile time.
 */
final class SubagentToolHandler
{
    public const string NAME = 'subagent';

    public const string DESCRIPTION = 'Launch one interactive foreground subagent with "agent" and "task". Submit independent subagent, fork, or other tool calls together for concurrent execution. The call waits for its child and returns the full handoff inline. Responses over 50,000 characters return a notice and artifact references instead. Use agent_retrieve for omitted handoffs or extra detail.';

    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        /** @var ContainerInterface SubagentExecutionService is resolved only on invoke. */
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    public function __invoke(
        #[MapToolArguments]
        SubagentArgumentsDTO $arguments,
    ): DeferredToolCompletionOutcome {
        return $this->toolRuntime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The subagent tool requires an active parent run context. Subagents cannot be launched outside a session.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('Subagent tool requires a valid parent run ID. No run context is active.', retryable: false);
            }

            return $this->executionService()->execute(
                parentRunId: $parentRunId,
                agentName: trim($arguments->agent),
                task: trim($arguments->task),
            );
        });
    }

    private function executionService(): SubagentExecutionService
    {
        if (!$this->executionServiceLocator->has(self::EXECUTION_SERVICE_LOCATOR_KEY)) {
            throw new \LogicException('Subagent execution service is not registered in the subagent tool locator.');
        }

        $service = $this->executionServiceLocator->get(self::EXECUTION_SERVICE_LOCATOR_KEY);
        if (!$service instanceof SubagentExecutionService) {
            throw new \LogicException(\sprintf('Subagent tool locator entry must be SubagentExecutionService, got %s.', get_debug_type($service)));
        }

        return $service;
    }
}
