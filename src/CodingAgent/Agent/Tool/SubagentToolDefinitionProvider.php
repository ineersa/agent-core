<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

/**
 * Lightweight permanent-tool provider for `subagent`.
 *
 * Does not depend on SubagentExecutionService or the prompt/policy stack,
 * so ToolRegistry can seed definitions without a compile-time DI cycle.
 *
 * Decision rules belong in promptGuidelines; the schema is generated from
 * SubagentArgumentsDTO. Both affect provider request cassette signatures.
 */
final class SubagentToolDefinitionProvider implements HatfieldToolProviderInterface
{
    public function __construct(
        private readonly SubagentToolHandler $handler,
    ) {
    }

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: SubagentToolHandler::NAME,
            description: SubagentToolHandler::DESCRIPTION,
            handler: $this->handler,
            executionMode: ToolExecutionMode::Parallel,
            timeoutSeconds: null,
            promptLine: 'subagent — launch one interactive subagent; oversized handoffs return artifact references',
            promptGuidelines: [
                'Use {"agent":"...","task":"..."} for one child. Submit separate independent tool calls together to run them concurrently. Wait for prerequisites before submitting dependent work.',
                'Concurrent writers must not target the same worktree or file. Preserve worktree ownership when delegating.',
                'Foreground calls return the handoff inline; background calls deliver it automatically. Responses over 50,000 characters return artifact references instead. Use agent_retrieve with the Artifact: ID for omitted handoffs, failures, metadata, or history.',
            ],
        );
    }
}
