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
 *
 * Provider-facing description and parametersJsonSchema stay stable with
 * origin/main so live-provider tool schemas reuse proven llama-proxy
 * cassettes. Decision-rule guidance belongs in promptGuidelines (and
 * skills/docs/prompts); llama-proxy normalizes leading messages but not
 * tools, so schema text changes create cold cache keys.
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
                'Batch independent scouts/reviewers in one {"tasks":[{"agent":"...","task":"..."}]} call; use {"agent":"...","task":"..."} for one child or dependent/serialized work.',
                \sprintf(
                    'Tasks in one call run concurrently (max %d); separate outer subagent calls serialize. Split only for cap overflow or true dependencies.',
                    $maxAgents,
                ),
                'Single-mode success includes full handoff inline (agent_retrieve optional). Parallel results are bounded summaries — use agent_retrieve with each Artifact: ID for complete handoffs, failures, metadata, or history.',
            ],
        );
    }
}
