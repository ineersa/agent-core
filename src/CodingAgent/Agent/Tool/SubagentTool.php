<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Execution\SubagentExecutionService;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRuntime;

/**
 * Model-visible `subagent` tool for single foreground agent execution.
 *
 * Launches a parent-scoped child agent run, waits for completion,
 * pushes live progress into the inline tool result widget, and returns
 * the final handoff as the tool result.
 *
 * Only single foreground mode is supported in v1.  Parallel,
 * background, and interactive modes are explicitly rejected with
 * actionable error messages.
 *
 * Implements both HatfieldToolProviderInterface and ToolHandlerInterface
 * for automatic registration as a permanent tool.
 */
final class SubagentTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    public function __construct(
        private readonly SubagentExecutionService $executionService,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ToolRuntime $toolRuntime,
    ) {
    }

    /**
     * Execute the subagent tool.
     *
     * @param array<string, mixed> $arguments must contain 'agent' (string) and
     *                                        'task' (string)
     *
     * @return string handoff/result text
     *
     * @throws ToolCallException on validation or execution errors
     */
    public function __invoke(array $arguments): string
    {
        return $this->toolRuntime->run(function () use ($arguments): string {
            // Require parent run context.
            $context = $this->contextAccessor->current();
            if (null === $context) {
                throw new ToolCallException('The subagent tool requires an active parent run context. Subagents cannot be launched outside a session.', retryable: false);
            }

            // Parse and validate arguments.
            $agent = $this->validateString($arguments, 'agent');
            $task = $this->validateString($arguments, 'task');

            // Reject unsupported parallel/background modes at runtime.
            if (isset($arguments['tasks']) && \is_array($arguments['tasks'])) {
                throw new ToolCallException('Parallel subagent execution (tasks array) is not yet implemented. Use single agent mode with "agent" and "task" fields.', retryable: false, hint: 'Use {"agent": "scout", "task": "your task here"} instead of {"tasks": [...]}.');
            }
            if (isset($arguments['concurrency'])) {
                throw new ToolCallException('Parallel subagent execution (concurrency) is not yet implemented. Use single agent mode.', retryable: false);
            }
            if (isset($arguments['background']) && true === $arguments['background']) {
                throw new ToolCallException('Background subagent execution is not yet implemented. Use foreground mode by omitting the "background" field.', retryable: false);
            }

            $parentRunId = $context->runId();
            if ('' === $parentRunId) {
                throw new ToolCallException('Subagent tool requires a valid parent run ID. No run context is active.', retryable: false);
            }

            // Delegate to the execution service.
            return $this->executionService->execute(
                parentRunId: $parentRunId,
                agentName: $agent,
                task: $task,
            );
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'subagent',
            description: 'Launch a non-interactive foreground subagent to perform a focused task. The subagent runs independently and returns a dense handoff when complete. Only single agent mode is supported — use "agent" (name) and "task" (description).',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'agent' => [
                        'type' => 'string',
                        'description' => 'Name of the agent definition to launch (e.g., "scout", "reviewer", "worker"). Must match an enabled agent definition.',
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'The task for the subagent. Be specific about what to find, check, or produce.',
                    ],
                ],
                'required' => ['agent', 'task'],
                'additionalProperties' => false,
            ],
            handler: $this,
            executionMode: ToolExecutionMode::Sequential,
            promptLine: 'subagent agent=<name> task=<description> — launch a non-interactive foreground subagent to perform a focused task; returns a dense handoff on completion',
            promptGuidelines: [
                'Use subagent to delegate focused read-only analysis or review work to a specialized child agent.',
                'Subagents run in the foreground and block until complete. The tool result is the subagent\'s final handoff.',
                'Specify the agent name (matching an enabled definition like "scout" or "reviewer") and a concrete task.',
                'Subagents write their results as artifacts under the parent session — no top-level session is created.',
                'Subagents are non-interactive and cannot ask for human input. If they need information, they return a needs-clarification handoff.',
                'Currently only single-agent mode is supported. Parallel execution and background/async modes are not yet available.',
                'Use subagent for codebase exploration, code review, research, or focused implementation work.',
            ],
        );
    }

    /**
     * Validate a required non-empty string argument.
     *
     * @param array<string, mixed> $arguments
     *
     * @return string the validated value
     *
     * @throws ToolCallException when the argument is missing, empty, or not a string
     */
    private function validateString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new ToolCallException(\sprintf('The "%s" argument is required and must be a non-empty string.', $key), retryable: false, hint: \sprintf('Provide a non-empty string for "%s", e.g. {"agent": "scout", "task": "inspect routing config"}.', $key));
        }

        return trim($value);
    }
}
