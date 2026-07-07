<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;

final class ForkToolDefinitionBuilder
{
    public static function build(ToolHandlerInterface $handler): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'fork',
            description: 'Launch an interactive fork child with inherited compacted parent history. The tool blocks until the fork completes. Use /agents-live to monitor; use agent_retrieve for handoff/metadata after completion.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'task' => [
                        'type' => 'string',
                        'description' => 'Delegated task for the fork child (required).',
                    ],
                    'cwd' => [
                        'type' => 'string',
                        'description' => 'Optional working directory hint for the fork child (reserved; parent cwd is used today).',
                    ],
                    'level' => [
                        'type' => 'string',
                        'enum' => ['junior', 'middle', 'senior'],
                        'description' => 'Fork level (model override from forks settings).',
                    ],
                ],
                'required' => ['task'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            executionMode: ToolExecutionMode::Sequential,
            timeoutSeconds: null,
            promptLine: 'fork task="..." [level=junior|middle|senior] — launch fork child with inherited history',
            promptGuidelines: [
                'Use fork to delegate implementation or investigation to a child with full main-agent tool scope except fork itself.',
                'Fork children may use subagent; they cannot launch another fork.',
                'Results include artifact_id and agent_run_id; use agent_retrieve for metadata, events, or history.',
            ],
        );
    }
}
