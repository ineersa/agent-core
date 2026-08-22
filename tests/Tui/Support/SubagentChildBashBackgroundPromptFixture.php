<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Parent + child fixture for agent_child live-view leave/re-enter without a bash
 * background overlay. Child bash is represented by an in-flight tool_execution_start
 * only — product policy no longer creates child background ToolQuestions.
 */
final class SubagentChildBashBackgroundPromptFixture
{
    public static function write(string $projectDir, string $sessionId): void
    {
        $childRunId = SubagentChildLiveViewFixtureSupport::childRunId($sessionId);
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $artifactId = SubagentChildLiveViewFixtureSupport::ARTIFACT_ID;

        $childEvents = [
            SubagentChildLiveViewFixtureSupport::childEvent($childRunId, 1, 0, 'run_started', [
                'step_id' => 'cstart',
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => $sessionId,
                            'agent_name' => 'scout',
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
            ], $now),
            SubagentChildLiveViewFixtureSupport::childEvent($childRunId, 2, 1, 'tool_execution_start', [
                'tool_call_id' => 'call_child_bash_bg_marker_9f3c',
                'tool_name' => 'bash',
                'order_index' => 0,
                'mode' => 'parallel',
                'arguments' => [
                    'command' => 'sleep 30 # child-bash-bg-marker-9f3c',
                    'timeout' => 60,
                ],
            ], $now),
        ];

        SubagentChildLiveViewFixtureSupport::write(
            $projectDir,
            $sessionId,
            'running',
            'running',
            $childEvents,
        );
    }
}
