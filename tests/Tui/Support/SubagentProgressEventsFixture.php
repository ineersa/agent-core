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

        $progressBase = [
            'mode' => 'single',
            'status' => 'running',
            'agent_name' => 'scout',
            'artifact_id' => $artifactId,
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
        ];

        $events = [];
        $events[] = self::event($sessionId, 1, 0, 'run_started', [
            'step_id' => 'start-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Run a scout subagent.']]],
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
        $events[] = self::event($sessionId, 11, 2, 'turn_advanced', ['step_id' => 'turn-2', 'turn_no' => 2, 'parent_turn_no' => null], $now);
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
