<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
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
    public static function build(object $handler): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: SubagentToolHandler::NAME,
            description: SubagentToolHandler::DESCRIPTION,
            handler: $handler,
            executionMode: ToolExecutionMode::Parallel,
            timeoutSeconds: null,
            promptLine: 'subagent — launch one interactive subagent; oversized handoffs return artifact references',
            promptGuidelines: [
                'Use {"agent":"...","task":"..."} for one child. Submit separate independent tool calls together to run them concurrently, like Bash or fork calls. Wait for prerequisites before submitting dependent work.',
                'Concurrent writers must not target the same worktree or file. Preserve worktree ownership when delegating.',
                'Foreground calls return the handoff inline; background calls deliver it automatically. Responses over 50,000 characters return artifact references instead. Use agent_retrieve with the Artifact: ID for omitted handoffs, failures, metadata, or history.',
            ],
        );
    }
}
