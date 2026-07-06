<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;

/**
 * Builds the permanent `subagent` tool definition metadata shared by the
 * definition provider and tests.
 */
final class SubagentToolDefinitionBuilder
{
    public static function build(AgentsConfig $agentsConfig, ToolHandlerInterface $handler): ToolDefinitionDTO
    {
        $maxAgents = $agentsConfig->maxAgents;

        return new ToolDefinitionDTO(
            name: 'subagent',
            description: \sprintf(
                'Launch interactive foreground subagent(s). Single mode uses "agent" and "task". Parallel mode uses "tasks" with up to %d agents per call (agents.max_agents). The tool blocks until all children finish. Single-mode results include the full child handoff inline; parallel results are bounded summaries — use agent_retrieve for complete parallel handoffs or extra detail.',
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
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'subagent — launch one or more interactive foreground subagents; single mode returns full handoff inline',
            promptGuidelines: [
                'Use subagent to delegate focused work to specialized child agents.',
                \sprintf('Parallel mode: {"tasks":[{"agent":"scout","task":"..."}]} — up to %d agents per call (configured by agents.max_agents).', $maxAgents),
                'Single mode: {"agent":"scout","task":"..."}.',
                \sprintf('If more than %d parallel agents are needed, split into multiple subagent calls.', $maxAgents),
                'The "concurrency" argument is not supported; all tasks in one call run concurrently up to the cap.',
                'Single-mode successful results include the full child handoff inline — agent_retrieve is optional (metadata/history/debug only).',
                'Parallel results are bounded summaries; use agent_retrieve with each Artifact: ID for complete handoffs.',
                'Use agent_retrieve for failed/cancelled/timed-out children, truncated output, metadata, events/history, or debug paths.',
                'Artifact: lines identify child artifacts for tracking and retrieval when needed.',
            ],
        );
    }
}
