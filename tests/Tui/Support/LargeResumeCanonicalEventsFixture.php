<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Substantial synthetic canonical resume fixture for packaged TUI memory proof.
 *
 * Shape mirrors the measured session-2 retention risk (hundreds of markdown
 * assistant/thinking blocks plus many tool exchanges) without private content.
 */
final class LargeResumeCanonicalEventsFixture
{
    public static function write(string $projectDir, string $sessionId): void
    {
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir) && !mkdir($sessionDir, 0777, true) && !is_dir($sessionDir)) {
            throw new \RuntimeException('Failed to create session dir: '.$sessionDir);
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $handle = fopen($sessionDir.'/events.jsonl', 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Unable to open events.jsonl');
        }

        try {
            self::writeEvent($handle, [
                'schema_version' => '1.0',
                'run_id' => $sessionId,
                'seq' => 1,
                'turn_no' => 0,
                'type' => 'run_started',
                'payload' => [
                    'step_id' => 'start-1',
                    'payload' => [
                        'messages' => [[
                            'role' => 'user',
                            'content' => [['type' => 'text', 'text' => 'MEMORY_MARKER_EARLY please keep this long resume history.']],
                        ]],
                    ],
                ],
                'ts' => $now,
            ]);

            $seq = 2;
            $turn = 1;
            for ($i = 0; $i < 220; ++$i) {
                self::writeEvent($handle, [
                    'schema_version' => '1.0',
                    'run_id' => $sessionId,
                    'seq' => $seq++,
                    'turn_no' => $turn,
                    'type' => 'turn_advanced',
                    'payload' => ['step_id' => 'turn-'.$i, 'turn_no' => $turn],
                    'ts' => $now,
                ]);
                self::writeEvent($handle, [
                    'schema_version' => '1.0',
                    'run_id' => $sessionId,
                    'seq' => $seq++,
                    'turn_no' => $turn,
                    'type' => 'history_position_set',
                    'payload' => [
                        'position_turn_no' => $turn,
                        'previous_position_turn_no' => $turn > 1 ? $turn - 1 : null,
                        'reason' => 'continue',
                    ],
                    'ts' => $now,
                ]);

                $assistantText = 219 === $i
                    ? 'MEMORY_MARKER_LATE final retained assistant answer with **markdown**.'
                    : 'assistant step '.$i.' '.str_repeat('paragraph with **bold**, `code`, and a [link](https://example.test). ', 6 + ($i % 8));
                if (0 === $i % 11) {
                    $assistantText .= "\n\n```php\n".str_repeat("echo {$i};\n", 12)."```\n";
                }

                self::writeEvent($handle, [
                    'schema_version' => '1.0',
                    'run_id' => $sessionId,
                    'seq' => $seq++,
                    'turn_no' => $turn,
                    'type' => 'llm_step_completed',
                    'payload' => [
                        'step_id' => 'step-'.$i,
                        'stop_reason' => 0 === $i % 4 ? 'tool_call' : 'stop',
                        'assistant_message' => [
                            'role' => 'assistant',
                            'content' => [
                                ['type' => 'thinking', 'text' => 'thinking '.$i.' '.str_repeat('token ', 10)],
                                ['type' => 'text', 'text' => $assistantText],
                            ],
                            'tool_calls' => 0 === $i % 4 ? [[
                                'id' => 'call_'.$i,
                                'name' => 'read',
                                'arguments' => ['path' => '/tmp/synthetic-'.$i.'.txt', 'limit' => 20],
                                'order_index' => 0,
                            ]] : [],
                        ],
                        'usage' => ['input_tokens' => 20 + $i, 'output_tokens' => 10, 'total_tokens' => 30 + $i],
                    ],
                    'ts' => $now,
                ]);

                if (0 === $i % 4) {
                    $callId = 'call_'.$i;
                    self::writeEvent($handle, [
                        'schema_version' => '1.0',
                        'run_id' => $sessionId,
                        'seq' => $seq++,
                        'turn_no' => $turn,
                        'type' => 'tool_execution_start',
                        'payload' => [
                            'tool_call_id' => $callId,
                            'tool_name' => 'read',
                            'order_index' => 0,
                            'mode' => 'sequential',
                        ],
                        'ts' => $now,
                    ]);
                    self::writeEvent($handle, [
                        'schema_version' => '1.0',
                        'run_id' => $sessionId,
                        'seq' => $seq++,
                        'turn_no' => $turn,
                        'type' => 'tool_execution_end',
                        'payload' => [
                            'tool_result' => [
                                'run_id' => $sessionId,
                                'turn_no' => $turn,
                                'step_id' => 'step-'.$i,
                                'attempt' => 1,
                                'idempotency_key' => 'result-'.$callId,
                                'tool_call_id' => $callId,
                                'order_index' => 0,
                                'result' => [
                                    'tool_name' => 'read',
                                    'content' => [[
                                        'type' => 'text',
                                        'text' => str_repeat("synthetic tool output line {$i}\n", 24),
                                    ]],
                                ],
                                'is_error' => false,
                                'error' => null,
                                'pending_human_input' => null,
                            ],
                        ],
                        'ts' => $now,
                    ]);
                }

                ++$turn;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function writeEvent($handle, array $event): void
    {
        fwrite($handle, json_encode($event, \JSON_THROW_ON_ERROR)."\n");
    }
}
