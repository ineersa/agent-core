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
                'Use agent_resume to continue a terminal child or fork in the current parent lifetime by artifact_id (preferred) or agent_run_id with a focused continuation task. Do not duplicate relevant child context that remains resumable.',
                'If agent_resume explicitly refuses because the child belongs to a previous parent lifetime or exceeds its context budget, launch a replacement via subagent or fork with the relevant handoff. Do not treat a running child as non-resumable; wait for completion or use agent_steer for current guidance.',
                'Use independent agent_resume calls for concurrent continuations.',
                'Success includes the full latest handoff inline. Responses over 50,000 characters return a notice and artifact references instead. Use agent_retrieve for omitted handoffs; mode=handoff_history lists/fetches prior handoffs by handoff_id.',
            ],
        );
    }
}
