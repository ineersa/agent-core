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
                'Launch interactive foreground subagent(s). Batch independent scouts/reviewers in ONE parallel call: {"tasks":[{"agent":"...","task":"..."}, ...]} (up to %d; agents.max_agents). Use single mode {"agent":"...","task":"..."} only for exactly one child or work that must be serialized (dependent follow-ups, re-review after a fix). Separate subagent tool calls are serialized by the outer tool executor; children inside one tasks array run concurrently. The tool blocks until all children finish. Single-mode results include the full child handoff inline; parallel results are bounded summaries — use agent_retrieve for complete parallel handoffs or extra detail.',
                $maxAgents,
            ),
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'agent' => [
                        'type' => 'string',
                        'description' => 'Agent definition name for single mode (one child, or serialized/dependent work).',
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'Task text for single mode (one child, or serialized/dependent work).',
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
                            'Parallel tasks (max %d per call). Prefer this shape for independent scouts/reviewers so they run concurrently; use instead of agent/task for parallel mode.',
                            $maxAgents,
                        ),
                    ],
                ],
                'additionalProperties' => false,
            ],
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'subagent — launch one or more interactive foreground subagents; batch independent work in tasks; single mode returns full handoff inline',
            promptGuidelines: [
                'Use subagent to delegate focused work to specialized child agents.',
                'Decision rule: batch independent scouts/reviewers in ONE {"tasks":[...]} call whenever within agents.max_agents; use {"agent","task"} only for exactly one child or work that must be serialized.',
                'Correct independent batch (runs concurrently): {"tasks":[{"agent":"scout","task":"A"},{"agent":"scout","task":"B"}]}.',
                'Anti-pattern for independent work: multiple separate single-mode subagent calls — they are valid syntax but serialize, so independent reconnaissance waits unnecessarily.',
                'Correct sequential: launch scout B only after scout A returns when B depends on A\'s findings; re-review after a fix is sequential.',
                \sprintf(
                    'Cap: up to %d agents per tasks call (agents.max_agents). Split across multiple subagent calls only for cap overflow or true dependencies — not for routine independent work.',
                    $maxAgents,
                ),
                'The "concurrency" argument is not supported; all tasks in one call run concurrently up to the cap. Separate outer subagent tool calls remain sequential.',
                'Single-mode successful results include the full child handoff inline — agent_retrieve is optional (metadata/history/debug only).',
                'Parallel results are bounded summaries; use agent_retrieve with each Artifact: ID for complete handoffs.',
                'Use agent_retrieve for failed/cancelled/timed-out children, truncated output, metadata, events/history, or debug paths.',
                'Artifact: lines identify child artifacts for tracking and retrieval when needed.',
            ],
        );
    }
}
