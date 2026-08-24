<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Builds the permanent `agent_resume` tool definition metadata.
 */
final class AgentResumeToolDefinitionBuilder
{
    public static function build(AgentsConfig $agentsConfig, object $handler): ToolDefinitionDTO
    {
        $maxAgents = $agentsConfig->maxAgents;

        return new ToolDefinitionDTO(
            name: AgentResumeToolHandler::NAME,
            description: \sprintf(
                AgentResumeToolHandler::DESCRIPTION_TEMPLATE,
                $maxAgents,
            ),
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'agent_resume artifact_id=<id>|agent_run_id=<uuid> task=<text> — continue an existing terminal subagent',
            promptGuidelines: [
                'Use agent_resume to continue an existing child by artifact_id (preferred) or agent_run_id with a focused continuation task. Do not launch a duplicate via subagent when relevant child context already exists.',
                'Batch independent resumes in one {"tasks":[{"artifact_id":"...","task":"..."}]} call; use single-mode fields for one child or dependent/serialized work.',
                \sprintf('Tasks in one call run concurrently (max %d).', $maxAgents),
                'Single-mode success includes full latest handoff inline. Parallel results are bounded summaries — use agent_retrieve; mode=handoff_history lists/fetches prior handoffs by handoff_id.',
            ],
        );
    }
}
