<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;

final class ForkToolDefinitionBuilder
{
    public static function build(object $handler): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: ForkToolHandler::NAME,
            description: ForkToolHandler::DESCRIPTION,
            handler: $handler,
            executionMode: ToolExecutionMode::Parallel,
            timeoutSeconds: null,
            promptLine: 'fork task="..." — delegate work to an isolated child with inherited context',
            promptGuidelines: [
                'Fork children cannot launch fork or subagent; do not instruct them to spawn child agents.',
                'Parallel forks must NEVER target the same worktree/directory because concurrent edits can corrupt it.',
                'Never launch more than 3 forks concurrently because forks impose high load.',
                'Do not set model or thinking unless the user explicitly requested overrides.',
                'Give forks a precise implementation specification covering checkout, affected code, decided behavior and design, necessary invariants, and relevant validation. Include existing changes to preserve. Exclude orchestration history, previous-agent outcomes, workflow bookkeeping, and repeated project instructions. Apply this rule to follow-ups too; no mandatory headings.',
            ],
        );
    }
}
