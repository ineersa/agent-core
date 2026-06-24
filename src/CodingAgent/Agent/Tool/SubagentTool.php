<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Execution\SubagentArgumentsFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;

/**
 * Model-visible `subagent` tool for foreground agent execution.
 *
 * Supports single mode ({agent, task}) and parallel mode ({tasks: [...]}).
 */
final class SubagentTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly SubagentExecutionService $executionService,
        private readonly SubagentArgumentsFactory $argumentsFactory,
        private readonly AgentsConfig $agentsConfig,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
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

                return $this->executionService->executeParallel($parentRunId, $tasks);
            }

            return $this->executionService->execute(
                parentRunId: $parentRunId,
                agentName: (string) $parsed->trimmedAgent(),
                task: (string) $parsed->trimmedTask(),
            );
        });
    }

    public function definition(): ToolDefinitionDTO
    {
        $maxAgents = $this->agentsConfig->maxAgents;

        return new ToolDefinitionDTO(
            name: 'subagent',
            description: \sprintf(
                'Launch non-interactive foreground subagent(s). Single mode uses "agent" and "task". Parallel mode uses "tasks" with up to %d agents per call (agents.max_agents). The tool blocks until all children finish and returns per-child artifact IDs.',
                $maxAgents,
            ),
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'agent' => [
                        'type' => 'string',
                        'description' => 'Agent definition name for single mode.',
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'Task text for single mode.',
                    ],
                    'tasks' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => $maxAgents,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'agent' => ['type' => 'string'],
                                'task' => ['type' => 'string'],
                            ],
                            'required' => ['agent', 'task'],
                            'additionalProperties' => false,
                        ],
                        'description' => \sprintf('Parallel tasks (max %d per call). Use instead of agent/task for parallel mode.', $maxAgents),
                    ],
                ],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'subagent — launch one or more non-interactive foreground subagents; returns artifact IDs for agent_retrieve',
            promptGuidelines: [
                'Use subagent to delegate focused work to specialized child agents.',
                \sprintf('Parallel mode: {"tasks":[{"agent":"scout","task":"..."}]} — up to %d agents per call (configured by agents.max_agents).', $maxAgents),
                'Single mode: {"agent":"scout","task":"..."}.',
                \sprintf('If more than %d parallel agents are needed, split into multiple subagent calls.', $maxAgents),
                'The "concurrency" argument is not supported; all tasks in one call run concurrently up to the cap.',
                'Successful results include Artifact: lines for agent_retrieve.',
            ],
        );
    }
}
