<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;

final class ForkToolDefinitionBuilder
{
    public static function build(ToolHandlerInterface $handler): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'fork',
            description: 'Launch an isolated fork child with inherited parent conversation context. Blocks until the fork completes and returns a dense handoff through deferred tool completion.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string',
                        'description' => 'Delegated task for the fork child (required).',
                    ],
                    'model' => [
                        'type' => 'string',
                        'description' => 'Optional model override.',
                    ],
                    'thinking' => [
                        'type' => 'string',
                        'enum' => ModelResolver::LEVELS,
                        'description' => 'Optional reasoning/thinking level.',
                    ],
                ],
                'required' => ['task'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            executionMode: ToolExecutionMode::Parallel,
            timeoutSeconds: null,
            promptLine: 'fork task="..." — delegate work to an isolated child with inherited context',
            promptGuidelines: [
                'Use fork for implementation delegation to an isolated child with inherited parent context.',
                'Fork children cannot launch fork or subagent; do not instruct them to spawn child agents.',
                'Parallel forks must NEVER target the same worktree/directory because concurrent edits can corrupt it.',
                'Never launch more than 3 forks concurrently because forks impose high load.',
                'Do not set model or thinking unless the user explicitly requested overrides.',
            ],
        );
    }
}
