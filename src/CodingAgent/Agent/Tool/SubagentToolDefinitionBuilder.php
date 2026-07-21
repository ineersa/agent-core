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
 * Keep description/schema/guidelines compact: this text is duplicated into
 * every parent system prompt and every provider tools schema. Detailed
 * examples live in skills/docs/prompts, not here (llama-proxy normalizes
 * leading messages but not tools, so verbose tool metadata creates cold keys).
 */
final class SubagentToolDefinitionBuilder
{
    public static function build(AgentsConfig $agentsConfig, ToolHandlerInterface $handler): ToolDefinitionDTO
    {
        $maxAgents = $agentsConfig->maxAgents;

        return new ToolDefinitionDTO(
            name: 'subagent',
            description: \sprintf(
                'Launch interactive foreground subagent(s). Batch independent work in one {"tasks":[{"agent":"...","task":"..."}]} call (up to %d; agents.max_agents); use single {"agent":"...","task":"..."} for one child or dependent/serialized work. Tasks in one call run concurrently; separate outer subagent calls serialize. Blocks until children finish. Single-mode results include the full handoff inline; parallel results are bounded summaries — use agent_retrieve with Artifact: IDs for complete parallel handoffs.',
                $maxAgents,
            ),
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'agent' => [
                        'type' => 'string',
                        'description' => 'Agent name (single mode).',
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'Task text (single mode).',
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
                        'description' => \sprintf(
                            'Parallel tasks (max %d). Prefer for independent work; mutually exclusive with agent/task.',
                            $maxAgents,
                        ),
                    ],
                ],
                'additionalProperties' => false,
            ],
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'subagent — batch independent work in tasks; single mode for one child or dependent work; full handoff inline in single mode',
            promptGuidelines: [
                'Batch independent scouts/reviewers in one {"tasks":[{"agent":"...","task":"..."}]} call within agents.max_agents; use {"agent":"...","task":"..."} only for one child or dependent/serialized work.',
                \sprintf(
                    'Tasks in one call run concurrently (max %d); separate outer subagent calls serialize. Split calls only for cap overflow or true dependencies.',
                    $maxAgents,
                ),
                'Single-mode success includes full handoff inline (agent_retrieve optional). Parallel results are bounded summaries — use agent_retrieve with each Artifact: ID for complete handoffs, failures, metadata, or history.',
            ],
        );
    }
}
