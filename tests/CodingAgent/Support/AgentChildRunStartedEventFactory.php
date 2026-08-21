<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

/**
 * Narrow helper for seeding canonical agent_child RunStarted events in BashTool tests.
 */
final class AgentChildRunStartedEventFactory
{
    public static function create(
        string $runId,
        string $artifactId = 'agent_bash_child',
        int $seq = 1,
    ): RunEvent {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'child-start',
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'child_kind' => 'fork',
                            'parent_run_id' => 'parent-run',
                            'agent_name' => 'fork',
                            'artifact_id' => $artifactId,
                            'interactive' => true,
                        ],
                        'model' => 'llama_cpp_test/test',
                        'reasoning' => 'off',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => ['mode' => 'none', 'tools' => []],
                        ],
                        'extensions' => [],
                    ],
                ],
            ],
        );
    }
}
