<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Canonical events.jsonl fixture proving structured subagent progress replay in TUI.
 */
final class SubagentProgressEventsFixture
{
    public static function write(string $projectDir, string $sessionId): void
    {
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $toolCallId = 'call_subagent_e2e_001';
        $artifactId = 'agent_e2e_progress_fixture';
        $childRunId = $sessionId.'_child_scout_001';

        $progressBase = [
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => $artifactId,
            'agent_run_id' => $childRunId,
            'task_summary' => 'Inspect TUI subagent rendering',
            'elapsed_ms' => 5000,
            'tool_count' => 12,
            'total_tokens' => 49000,
            'input_tokens' => 35000,
            'output_tokens' => 14000,
            'reasoning_tokens' => 584000,
            'cost' => 0.0104,
            'model' => 'deepseek/deepseek-v4-flash',
            'artifact_path' => 'artifacts/agents/'.$artifactId,
            'recent_tools' => ['read: path="src/Tui/Transcript/SubagentResultRenderer.php"'],
            'assistant_excerpt' => 'Structured subagent block renders inline.',
        ] + ChildContextStatisticsFixture::progressPayloadOverrides();

        $events = [];
        $events[] = self::event($sessionId, 1, 0, 'run_started', [
            'step_id' => 'start-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Run a scout subagent.']]],
                ],
            ],
        ], $now);
        $events[] = self::event($sessionId, 2, 1, 'turn_advanced', ['step_id' => 'turn-1', 'turn_no' => 1], $now);
        $events[] = self::event($sessionId, 3, 1, 'history_position_set', ['position_turn_no' => 1, 'previous_position_turn_no' => null, 'reason' => 'continue'], $now);
        $events[] = self::event($sessionId, 4, 1, 'llm_step_completed', [
            'step_id' => 'turn-1',
            'stop_reason' => 'tool_call',
            'tool_calls_count' => 1,
            'assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $toolCallId,
                    'name' => 'subagent',
                    'arguments' => ['agent' => 'scout', 'task' => 'Inspect TUI subagent rendering'],
                    'order_index' => 0,
                ]],
            ],
        ], $now);
        $events[] = self::event($sessionId, 5, 1, 'tool_execution_start', [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'subagent',
            'order_index' => 0,
            'mode' => 'sequential',
        ], $now);

        foreach ([1, 2, 3] as $turn) {
            $progress = $progressBase;
            $progress['turn_no'] = $turn;
            $progress['llm_step_count'] = $turn;
            $progress['elapsed_ms'] = 5000 + ($turn * 3000);
            $events[] = self::event($sessionId, 5 + $turn, 1, 'tool_execution_update', [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'subagent',
                'delta' => '',
                'subagent_progress' => $progress,
                'order_index' => 0,
            ], $now);
        }

        $progressTerminal = $progressBase;
        $progressTerminal['turn_no'] = 3;
        $progressTerminal['llm_step_count'] = 3;
        $progressTerminal['status'] = 'completed';
        $progressTerminal['elapsed_ms'] = 14000;
        $events[] = self::event($sessionId, 9, 1, 'tool_execution_update', [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'subagent',
            'delta' => '',
            'subagent_progress' => $progressTerminal,
            'order_index' => 0,
        ], $now);

        $finalResult = "Subagent scout completed.\nArtifact: {$artifactId}\n\nDone.";
        $events[] = self::event($sessionId, 10, 1, 'tool_execution_end', [
            'tool_call_id' => $toolCallId,
            'order_index' => 0,
            'is_error' => false,
            'result' => $finalResult,
        ], $now);
        $events[] = self::event($sessionId, 11, 2, 'turn_advanced', ['step_id' => 'turn-2', 'turn_no' => 2], $now);
        $events[] = self::event($sessionId, 12, 2, 'llm_step_completed', [
            'step_id' => 'turn-2',
            'stop_reason' => 'stop',
            'text' => 'Subagent finished.',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Subagent finished.']],
            ],
        ], $now);

        $jsonl = '';
        foreach ($events as $event) {
            $jsonl .= json_encode($event, \JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($sessionDir.'/events.jsonl', $jsonl);

        ChildAgentExportEventsFixture::write(
            $projectDir,
            $sessionId,
            $artifactId,
            [
                ChildAgentExportEventsFixture::childEvent(
                    $childRunId,
                    1,
                    'run_started',
                    ['user_messages' => [['role' => 'user', 'content' => 'Child-only export marker scout-e2e']]],
                ),
            ],
        );
    }

    /**
     * Parallel completed children for /agents-live picker selection-highlight E2E.
     *
     * Produces three unique catalog rows via production parallel children shape.
     */
    public static function writeThreeCompletedChildren(string $projectDir, string $sessionId): void
    {
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $toolCallId = 'call_subagent_e2e_parallel_001';
        $children = [
            [
                'index' => 1,
                'agent_name' => 'alpha',
                'status' => 'completed',
                'artifact_id' => 'agent_e2e_alpha_pick',
                'agent_run_id' => $sessionId.'_child_alpha_001',
                'task_summary' => 'Alpha unique picker row',
            ],
            [
                'index' => 2,
                'agent_name' => 'bravo',
                'status' => 'completed',
                'artifact_id' => 'agent_e2e_bravo_pick',
                'agent_run_id' => $sessionId.'_child_bravo_001',
                'task_summary' => 'Bravo unique picker row',
            ],
            [
                'index' => 3,
                'agent_name' => 'charlie',
                'status' => 'completed',
                'artifact_id' => 'agent_e2e_charlie_pick',
                'agent_run_id' => $sessionId.'_child_charlie_001',
                'task_summary' => 'Charlie unique picker row',
            ],
        ];

        $progress = [
            'mode' => 'parallel',
            'status' => 'completed',
            'completed_count' => 3,
            'total_count' => 3,
            'elapsed_ms' => 9000,
            'children' => $children,
            'tool_count' => 3,
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'reasoning_tokens' => 0,
            'total_tokens' => 1500,
        ];

        $events = [];
        $events[] = self::event($sessionId, 1, 0, 'run_started', [
            'step_id' => 'start-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Run three parallel subagents.']]],
                ],
            ],
        ], $now);
        $events[] = self::event($sessionId, 2, 1, 'turn_advanced', ['step_id' => 'turn-1', 'turn_no' => 1], $now);
        $events[] = self::event($sessionId, 3, 1, 'history_position_set', ['position_turn_no' => 1, 'previous_position_turn_no' => null, 'reason' => 'continue'], $now);
        $events[] = self::event($sessionId, 4, 1, 'llm_step_completed', [
            'step_id' => 'turn-1',
            'stop_reason' => 'tool_call',
            'tool_calls_count' => 1,
            'assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $toolCallId,
                    'name' => 'subagent',
                    'arguments' => [
                        'tasks' => [
                            ['agent' => 'alpha', 'task' => 'Alpha unique picker row'],
                            ['agent' => 'bravo', 'task' => 'Bravo unique picker row'],
                            ['agent' => 'charlie', 'task' => 'Charlie unique picker row'],
                        ],
                    ],
                    'order_index' => 0,
                ]],
            ],
        ], $now);
        $events[] = self::event($sessionId, 5, 1, 'tool_execution_start', [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'subagent',
            'order_index' => 0,
            'mode' => 'sequential',
        ], $now);
        $events[] = self::event($sessionId, 6, 1, 'tool_execution_update', [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'subagent',
            'delta' => '',
            'subagent_progress' => $progress,
            'order_index' => 0,
        ], $now);
        $events[] = self::event($sessionId, 7, 1, 'tool_execution_end', [
            'tool_call_id' => $toolCallId,
            'order_index' => 0,
            'is_error' => false,
            'result' => "Parallel subagents completed.\nArtifacts: agent_e2e_alpha_pick, agent_e2e_bravo_pick, agent_e2e_charlie_pick",
        ], $now);
        $events[] = self::event($sessionId, 8, 2, 'turn_advanced', ['step_id' => 'turn-2', 'turn_no' => 2], $now);
        $events[] = self::event($sessionId, 9, 2, 'llm_step_completed', [
            'step_id' => 'turn-2',
            'stop_reason' => 'stop',
            'text' => 'Parallel subagents finished.',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Parallel subagents finished.']],
            ],
        ], $now);

        $jsonl = '';
        foreach ($events as $event) {
            $jsonl .= json_encode($event, \JSON_THROW_ON_ERROR)."\n";
        }
        file_put_contents($sessionDir.'/events.jsonl', $jsonl);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function event(string $runId, int $seq, int $turnNo, string $type, array $payload, string $ts): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => $turnNo,
            'type' => $type,
            'payload' => $payload,
            'ts' => $ts,
        ];
    }
}
