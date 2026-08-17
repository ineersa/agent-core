<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\CodingAgent\Agent\Execution\SubagentArgumentsDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Container\ContainerInterface;

/**
 * Execution handler for the `subagent` tool.
 *
 * Resolves SubagentExecutionService only at invocation time via a narrow
 * service locator so ToolRegistry can register the tool definition without
 * constructing the heavy subagent execution graph at container compile time.
 */
// SubagentToolDefinitionBuilder formats DESCRIPTION_TEMPLATE with
// agents.max_agents for the canonical schema.
final class SubagentToolHandler
{
    public const string NAME = 'subagent';

    /**
     * Provider-visible description template; SubagentToolDefinitionBuilder
     * formats the %d with agents.max_agents for the canonical registry
     * metadata.
     */
    public const string DESCRIPTION_TEMPLATE = 'Launch interactive foreground subagent(s). Single mode uses "agent" and "task". Parallel mode uses "tasks" with up to %d agents per call (agents.max_agents). The tool blocks until all children finish. Single-mode results include the full child handoff inline; parallel results are bounded summaries — use agent_retrieve for complete parallel handoffs or extra detail.';

    private const string EXECUTION_SERVICE_LOCATOR_KEY = 'execution';

    public function __construct(
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
        /** @var ContainerInterface SubagentExecutionService is resolved only on invoke. */
        private readonly ContainerInterface $executionServiceLocator,
    ) {
    }

    public function __invoke(SubagentArgumentsDTO $arguments): DeferredToolCompletionOutcome
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

            $parsed = $arguments;

            if ($parsed->isParallelMode()) {
                // The per-call task-count limit (agents.max_agents) is enforced
                // by SubagentTasksLimit on SubagentArgumentsDTO; execution only
                // sees validated arguments.
                return $this->executionService()->executeParallel($parentRunId, $parsed->parallelTasks());
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
