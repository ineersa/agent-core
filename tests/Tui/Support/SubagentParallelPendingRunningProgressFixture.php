<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Canonical events.jsonl fixture: one parallel subagent tool call with two children
 * simultaneously visible as pending + running (stable intermediate snapshot).
 */
final class SubagentParallelPendingRunningProgressFixture
{
    public const string ARTIFACT_SCOUT = 'agent_e2e_parallel_scout';
    public const string ARTIFACT_WORKER = 'agent_e2e_parallel_worker';
    public const string AGENT_SCOUT = 'scout';
    public const string AGENT_WORKER = 'worker';

    public static function write(string $projectDir, string $sessionId): void
    {
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $toolCallId = 'call_subagent_parallel_e2e_001';
        $childScout = $sessionId.'_child_scout_parallel';
        $childWorker = $sessionId.'_child_worker_parallel';

        $pendingBoth = [
            'mode' => 'parallel',
            'status' => 'running',
            'completed_count' => 0,
            'total_count' => 2,
            'elapsed_ms' => 1200,
            'children' => [
                [
                    'index' => 1,
                    'status' => 'pending',
                    'agent_name' => self::AGENT_SCOUT,
                    'artifact_id' => self::ARTIFACT_SCOUT,
                    'agent_run_id' => $childScout,
                    'task_summary' => 'Parallel scout task',
                    'model' => 'deepseek/deepseek-v4-flash',
                ],
                [
                    'index' => 2,
                    'status' => 'pending',
                    'agent_name' => self::AGENT_WORKER,
                    'artifact_id' => self::ARTIFACT_WORKER,
                    'agent_run_id' => $childWorker,
                    'task_summary' => 'Parallel worker task',
                    'model' => 'deepseek/deepseek-v4-flash',
                ],
            ],
        ];

        $pendingAndRunning = [
            'mode' => 'parallel',
            'status' => 'running',
            'completed_count' => 0,
            'total_count' => 2,
            'elapsed_ms' => 4200,
            'children' => [
                [
                    'index' => 1,
                    'status' => 'running',
                    'agent_name' => self::AGENT_SCOUT,
                    'artifact_id' => self::ARTIFACT_SCOUT,
                    'agent_run_id' => $childScout,
                    'task_summary' => 'Parallel scout task',
                    'turn_no' => 2,
                    'tool_count' => 3,
                    'elapsed_ms' => 3000,
                    'model' => 'deepseek/deepseek-v4-flash',
                    'recent_tools' => ['read: path="src/Tui/Runtime/SubagentLiveCatalog.php"'],
                    'assistant_excerpt' => 'Scout child is actively working.',
                ] + ChildContextStatisticsFixture::progressPayloadOverrides(),
                [
                    'index' => 2,
                    'status' => 'pending',
                    'agent_name' => self::AGENT_WORKER,
                    'artifact_id' => self::ARTIFACT_WORKER,
                    'agent_run_id' => $childWorker,
                    'task_summary' => 'Parallel worker task',
                    'model' => 'deepseek/deepseek-v4-flash',
                ],
            ],
        ];

        $events = [];
        $events[] = self::event($sessionId, 1, 0, 'run_started', [
            'step_id' => 'start-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Run two scouts in parallel.']]],
                ],
            ],
        ], $now);
        $events[] = self::event($sessionId, 2, 1, 'turn_advanced', ['step_id' => 'turn-1', 'turn_no' => 1, 'parent_turn_no' => null], $now);
        $events[] = self::event($sessionId, 3, 1, 'leaf_set', ['turn_no' => 1, 'previous_turn_no' => null, 'parent_turn_no' => null, 'reason' => 'continue'], $now);
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
                            ['agent' => self::AGENT_SCOUT, 'task' => 'Parallel scout task'],
                            ['agent' => self::AGENT_WORKER, 'task' => 'Parallel worker task'],
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
            'subagent_progress' => $pendingBoth,
            'order_index' => 0,
        ], $now);
        // Terminal snapshot for the proof: one running + one still pending simultaneously.
        $events[] = self::event($sessionId, 7, 1, 'tool_execution_update', [
            'tool_call_id' => $toolCallId,
            'tool_name' => 'subagent',
            'delta' => '',
            'subagent_progress' => $pendingAndRunning,
            'order_index' => 0,
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
