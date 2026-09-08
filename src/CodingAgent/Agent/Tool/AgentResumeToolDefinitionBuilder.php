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
            promptLine: 'agent_resume artifact_id=<id>|agent_run_id=<uuid> task=<text> — continue an existing terminal subagent or fork',
            promptGuidelines: [
                'Use agent_resume to continue an existing child or fork by artifact_id (preferred) or agent_run_id with a focused continuation task. Do not launch a duplicate via subagent or fork when relevant child context already exists.',
                'Resuming a fork keeps the same child identity and conversation; the follow-up task must explicitly hand off checkout ownership and require inspecting current file state before resumed edits.',
                'Batch independent resumes in one {"tasks":[{"artifact_id":"...","task":"..."}]} call; use single-mode fields for one child or dependent/serialized work.',
                \sprintf('Tasks in one call run concurrently (max %d).', $maxAgents),
                'Single-mode success includes the full latest handoff inline; parallel results include summaries. Responses over 50,000 characters return a notice and artifact references instead. Use agent_retrieve for omitted handoffs; mode=handoff_history lists/fetches prior handoffs by handoff_id.',
            ],
        );
    }
}
