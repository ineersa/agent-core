<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Builds the permanent `agent_resume` tool definition metadata.
 */
final class AgentResumeToolDefinitionBuilder
{
    public static function build(object $handler): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: AgentResumeToolHandler::NAME,
            description: AgentResumeToolHandler::DESCRIPTION_TEMPLATE,
            handler: $handler,
            executionMode: ToolExecutionMode::Parallel,
            timeoutSeconds: null,
            promptLine: 'agent_resume artifact_id=<id>|agent_run_id=<uuid> task=<text> — continue an existing terminal subagent or fork',
            promptGuidelines: [
                'Use agent_resume to continue an existing child or fork by artifact_id (preferred) or agent_run_id with a focused continuation task. Do not launch a duplicate via subagent or fork when relevant child context already exists.',
                'Use independent agent_resume calls for concurrent continuations.',
                'Success includes the full latest handoff inline. Responses over 50,000 characters return a notice and artifact references instead. Use agent_retrieve for omitted handoffs; mode=handoff_history lists/fetches prior handoffs by handoff_id.',
            ],
        );
    }
}
