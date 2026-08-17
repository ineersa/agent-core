<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Builds the permanent `subagent` tool definition metadata shared by the
 * definition provider and tests.
 *
 * Decision-rule guidance belongs in promptGuidelines (and skills/docs/prompts).
 * The provider schema is generated natively from SubagentArgumentsDTO.
 * Schema/prompt text changes can invalidate llama-proxy tool-schema cache keys.
 */
final class SubagentToolDefinitionBuilder
{
    public static function build(AgentsConfig $agentsConfig, object $handler): ToolDefinitionDTO
    {
        $maxAgents = $agentsConfig->maxAgents;

        return new ToolDefinitionDTO(
            name: SubagentToolHandler::NAME,
            description: \sprintf(
                SubagentToolHandler::DESCRIPTION_TEMPLATE,
                $maxAgents,
            ),
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'subagent — launch one or more interactive foreground subagents; single mode returns full handoff inline',
            promptGuidelines: [
                'Batch independent scouts/reviewers in one {"tasks":[{"agent":"...","task":"..."}]} call; use {"agent":"...","task":"..."} for one child or dependent/serialized work.',
                \sprintf(
                    'Tasks in one call run concurrently (max %d).',
                    $maxAgents,
                ),
                'Single-mode success includes full handoff inline (agent_retrieve optional). Parallel results are bounded summaries — use agent_retrieve with each Artifact: ID for complete handoffs, failures, metadata, or history.',
            ],
        );
    }
}
