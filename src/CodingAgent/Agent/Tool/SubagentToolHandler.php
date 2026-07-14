<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\SubagentArgumentsFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;

/**
 * Execution handler for the `subagent` tool.
 *
 * Resolves SubagentExecutionService only at invocation time via a narrow
 * service locator so ToolRegistry can register the tool definition without
 * constructing the heavy subagent execution graph at container compile time.
 */
final class SubagentToolHandler implements ToolHandlerInterface
{
    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly SubagentArgumentsFactory $argumentsFactory,
        private readonly AgentsConfig $agentsConfig,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        /** @var ContainerInterface SubagentExecutionService is resolved only on invoke. */
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): DeferredToolCompletionOutcome
    {
        return $this->toolRuntime->run(function () use ($arguments): DeferredToolCompletionOutcome {
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The subagent tool requires an active parent run context. Subagents cannot be launched outside a session.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('Subagent tool requires a valid parent run ID. No run context is active.', retryable: false);
            }

            $parsed = $this->argumentsFactory->fromToolArguments($arguments);

            if ($parsed->isParallelMode()) {
                $tasks = $parsed->parallelTasks();
                $maxAgents = $this->agentsConfig->maxAgents;
                if (\count($tasks) > $maxAgents) {
                    throw new ToolCallException(\sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $maxAgents, \count($tasks)), retryable: false, hint: \sprintf('Split the work into multiple subagent calls with at most %d tasks each.', $maxAgents));
                }

                return $this->executionService()->executeParallel($parentRunId, $tasks);
            }

            return $this->executionService()->execute(
                parentRunId: $parentRunId,
                agentName: (string) $parsed->trimmedAgent(),
                task: (string) $parsed->trimmedTask(),
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
