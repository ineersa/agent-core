<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Shared canonical resume replay fixture for virtual and tmux proofs.
 */
final class ResumeCanonicalEventsFixture
{
    public static function write(string $projectDir, string $sessionId): void
    {
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $events = [];

        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 1, 'turn_no' => 0, 'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Tell me about testing.']]]],
                ],
            ],
            'ts' => $now,
        ];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 2, 'turn_no' => 1, 'type' => 'turn_advanced', 'payload' => ['step_id' => 'turn-1', 'turn_no' => 1, 'parent_turn_no' => null], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 3, 'turn_no' => 1, 'type' => 'leaf_set', 'payload' => ['turn_no' => 1, 'previous_turn_no' => null, 'parent_turn_no' => null, 'reason' => 'continue'], 'ts' => $now];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 4, 'turn_no' => 1, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-1', 'stop_reason' => 'stop', 'text' => 'Here is the answer you requested.', 'tool_calls_count' => 0,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Here is the answer you requested.']],
                    'details' => ['thinking' => 'Let me think about this request carefully.'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 6, 'total_tokens' => 16],
            ],
            'ts' => $now,
        ];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 5, 'turn_no' => 2, 'type' => 'turn_advanced', 'payload' => ['step_id' => 'turn-2', 'turn_no' => 2, 'parent_turn_no' => null], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 6, 'turn_no' => 2, 'type' => 'leaf_set', 'payload' => ['turn_no' => 2, 'previous_turn_no' => 1, 'parent_turn_no' => null, 'reason' => 'continue'], 'ts' => $now];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 7, 'turn_no' => 2, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-2', 'stop_reason' => 'tool_call', 'text' => null, 'tool_calls_count' => 1,
                'assistant_message' => [
                    'role' => 'assistant', 'content' => null,
                    'tool_calls' => [['id' => 'call_read_e2e_001', 'name' => 'read', 'arguments' => ['path' => '/tmp/example.txt'], 'order_index' => 0]],
                    'details' => ['thinking' => 'I need to read the file the user mentioned.'],
                ],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70],
            ],
            'ts' => $now,
        ];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 8, 'turn_no' => 2, 'type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call_read_e2e_001', 'tool_name' => 'read', 'order_index' => 0, 'mode' => 'sequential'], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 9, 'turn_no' => 2, 'type' => 'tool_call_result_received', 'payload' => ['tool_call_id' => 'call_read_e2e_001', 'order_index' => 0, 'is_error' => false], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 10, 'turn_no' => 2, 'type' => 'tool_execution_end', 'payload' => ['tool_call_id' => 'call_read_e2e_001', 'order_index' => 0, 'is_error' => false], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 11, 'turn_no' => 3, 'type' => 'turn_advanced', 'payload' => ['step_id' => 'turn-3', 'turn_no' => 3, 'parent_turn_no' => null], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 12, 'turn_no' => 3, 'type' => 'leaf_set', 'payload' => ['turn_no' => 3, 'previous_turn_no' => 2, 'parent_turn_no' => null, 'reason' => 'continue'], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 13, 'turn_no' => 3, 'type' => 'tool_execution_start', 'payload' => ['tool_call_id' => 'call_cancel_e2e', 'tool_name' => 'bash', 'order_index' => 0, 'mode' => 'sequential'], 'ts' => $now];
        $events[] = ['schema_version' => '1.0', 'run_id' => $sessionId, 'seq' => 14, 'turn_no' => 3, 'type' => 'llm_step_aborted', 'payload' => ['step_id' => 'turn-3', 'stop_reason' => 'aborted', 'usage' => []], 'ts' => $now];

        $jsonl = '';
        foreach ($events as $event) {
            $jsonl .= json_encode($event, \JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($sessionDir.'/events.jsonl', $jsonl);
    }
}
