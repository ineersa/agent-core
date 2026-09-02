<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Export;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Session\Export\EffectiveModelContextProjector;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveModelContextProjector::class)]
final class EffectiveModelContextProjectorTest extends TestCase
{
    #[Test]
    public function projectsUncompactedMessagesInOrder(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS']]],
                    ['role' => 'user-context', 'content' => [['type' => 'text', 'text' => 'CTX']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'USER']]],
                ]],
            ]),
            $this->event(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'ASSIST']],
                    'tool_calls' => [
                        ['id' => 'c1', 'name' => 'bash', 'arguments' => '{"command":"echo hi"}'],
                    ],
                ],
                'stop_reason' => 'tool_call',
                'available_tools' => ['bash', 'read'],
                'available_tools_schema_tokens_estimate' => 99,
            ]),
            $this->event(3, 1, 'tool_execution_start', [
                'tool_call_id' => 'c1',
                'tool_name' => 'bash',
                'order_index' => 0,
            ]),
            $this->event(4, 1, 'tool_execution_end', [
                'tool_result' => [
                    'run_id' => 'run-1',
                    'turn_no' => 1,
                    'step_id' => 'tool-end-c1',
                    'attempt' => 1,
                    'idempotency_key' => 'result-c1',
                    'tool_call_id' => 'c1',
                    'order_index' => 0,
                    'result' => [
                        'tool_name' => 'bash',
                        'content' => [['type' => 'text', 'text' => 'hi']],
                    ],
                    'is_error' => false,
                    'error' => null,
                    'pending_human_input' => null,
                ],
            ]),
            $this->event(5, 1, 'tool_batch_committed', [
                'step_id' => 's2',
                'turn_no' => 1,
            ]),
        ]);

        $snapshot = $this->projector()->project($jsonl, 'run-1');
        $roles = array_map(static fn (array $m): string => (string) ($m['role'] ?? ''), $snapshot->messages);

        $this->assertSame(['system', 'user-context', 'user', 'assistant', 'tool'], $roles);
        $this->assertSame(['bash', 'read'], $snapshot->availableTools);
        $this->assertSame(99, $snapshot->availableToolsSchemaTokensEstimate);
        $this->assertNull($snapshot->compaction);
        $this->assertSame('bash', $snapshot->messages[3]['metadata']['tool_calls'][0]['name'] ?? null);
        $this->assertSame('c1', $snapshot->messages[4]['tool_call_id'] ?? null);
    }

    #[Test]
    public function compactedCheckpointReplacesSupersededHistoryAndKeepsOmSummary(): void
    {
        $summary = "These are condensed memories from earlier in this session.\n\n- Reflections: keep shipping.";
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'OLD_USER']]],
                ]],
            ]),
            $this->event(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OLD_ASSISTANT']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['bash'],
                'available_tools_schema_tokens_estimate' => 1,
            ]),
            $this->event(3, 1, 'context_compacted', [
                'trigger' => 'manual',
                'summary_text' => $summary,
                'messages_compacted' => 2,
                'messages_retained' => 2,
                'hook_metadata' => [
                    'om_source' => 'observational_memory',
                    'om_projection' => 'active_durable_memory',
                ],
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'SYS']], 'is_error' => false],
                    [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => "The conversation history before this point was compacted into the following handoff summary.\n\n<summary>\n{$summary}\n</summary>"]],
                        'is_error' => false,
                        'metadata' => ['compact_summary' => true],
                    ],
                ],
            ]),
            $this->event(4, 1, 'agent_command_applied', [
                'kind' => 'follow_up',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'NEW_USER']],
                ],
            ]),
            $this->event(5, 1, 'llm_step_completed', [
                'step_id' => 's3',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'NEW_ASSISTANT']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['read'],
                'available_tools_schema_tokens_estimate' => 7,
            ]),
        ]);

        $snapshot = $this->projector()->project($jsonl, 'run-1');
        $texts = [];
        foreach ($snapshot->messages as $message) {
            $parts = [];
            $content = \is_array($message['content'] ?? null) ? $message['content'] : [];
            foreach ($content as $block) {
                if (\is_array($block) && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
            $texts[] = implode('', $parts);
        }

        $joined = implode("\n", $texts);
        $this->assertStringNotContainsString('OLD_USER', $joined);
        $this->assertStringNotContainsString('OLD_ASSISTANT', $joined);
        $this->assertStringContainsString('condensed memories', $joined);
        $this->assertStringContainsString('NEW_USER', $joined);
        $this->assertStringContainsString('NEW_ASSISTANT', $joined);
        $this->assertTrue($snapshot->messages[1]['metadata']['compact_summary'] ?? false);
        $this->assertSame('observational_memory', $snapshot->compaction['hook_metadata']['om_source'] ?? null);
        $this->assertSame($summary, $snapshot->compaction['summary_text'] ?? null);
        $this->assertSame(['read'], $snapshot->availableTools);
        $this->assertSame(7, $snapshot->availableToolsSchemaTokensEstimate);
    }

    #[Test]
    public function emptyAvailableToolsSnapshotOverridesEarlierNonEmptyList(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
                ]],
            ]),
            $this->event(2, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'With tools']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => ['bash', 'read'],
                'available_tools_schema_tokens_estimate' => 88,
            ]),
            $this->event(3, 1, 'llm_step_completed', [
                'step_id' => 's3',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'No tools']],
                ],
                'stop_reason' => 'end_turn',
                'available_tools' => [],
                'available_tools_schema_tokens_estimate' => 0,
            ]),
        ]);

        $snapshot = $this->projector()->project($jsonl, 'run-1');
        $this->assertSame([], $snapshot->availableTools);
        $this->assertSame(0, $snapshot->availableToolsSchemaTokensEstimate);
    }

    #[Test]
    public function rejectsUnsupportedEventTypes(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            [
                'schema_version' => '1.0',
                'run_id' => 'run-1',
                'seq' => 2,
                'turn_no' => 1,
                'type' => 'not_a_real_event_type',
                'payload' => [],
                'ts' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsupported event type');
        $this->projector()->project($jsonl, 'run-1');
    }

    #[Test]
    public function rejectsMalformedCanonicalEventAmongValidEvents(): void
    {
        $events = [
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            $this->event(2, 1, 'turn_advanced', ['turn_no' => 1]),
        ];
        $events[1]['turn_no'] = 'not-an-int';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed or incompatible canonical event at line 2');
        $this->projector()->project($this->jsonl($events), 'run-1');
    }

    #[Test]
    public function rejectsMessageContentThatReplayWouldOtherwiseDrop(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => 'not-canonical-content'],
                ]],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('event seq 1 has a malformed message');
        $this->projector()->project($jsonl, 'run-1');
    }

    #[Test]
    public function rejectsMalformedLatestAvailableToolsSnapshot(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            $this->event(2, 1, 'turn_advanced', ['turn_no' => 1]),
            $this->event(3, 1, 'llm_step_completed', [
                'step_id' => 's2',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Done']],
                ],
                'available_tools' => 'bash',
                'available_tools_schema_tokens_estimate' => 12,
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('event seq 3 has malformed available_tools');
        $this->projector()->project($jsonl, 'run-1');
    }

    #[Test]
    public function rejectsDuplicateEventSequences(): void
    {
        $events = [
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            $this->event(1, 1, 'turn_advanced', ['turn_no' => 1]),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate event sequence(s): 1');
        $this->projector()->project($this->jsonl($events), 'run-1');
    }

    #[Test]
    public function wrapsReplayFailuresWithSessionContext(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            $this->event(2, 1, 'turn_advanced', ['turn_no' => 1]),
            $this->event(3, 1, 'waiting_human'),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot project model context for session run-1: retained event replay failed');
        $this->projector()->project($jsonl, 'run-1');
    }

    #[Test]
    public function rejectsMalformedHumanResponseMessage(): void
    {
        $jsonl = $this->jsonl([
            $this->event(1, 1, 'run_started', [
                'step_id' => 's1',
                'payload' => ['messages' => []],
            ]),
            $this->event(2, 1, 'turn_advanced', ['turn_no' => 1]),
            $this->event(3, 1, 'agent_command_applied', [
                'kind' => 'human_response',
                'question_id' => 'question-1',
                'message' => [
                    'role' => 'user',
                    'content' => 'not-canonical-content',
                ],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('event seq 3 has a malformed message');
        $this->projector()->project($jsonl, 'run-1');
    }

    private function projector(): EffectiveModelContextProjector
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();

        return new EffectiveModelContextProjector(
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            replayEventPreparer: new ReplayEventPreparer(),
            historyReplayFilter: new HistoryReplayFilter(new HistoryProjector()),
            runStateReducer: new RunStateReducer(
                AttributeSerializerValidatorTestFactory::denormalizer(),
                new ToolExecutionEndPayloadCodec($serializer),
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function jsonl(array $events): string
    {
        $normalized = [];
        $hasTurnAdvanced = false;
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'run_started') {
                $event['turn_no'] = 0;
            }
            if (($event['type'] ?? null) === 'turn_advanced') {
                $hasTurnAdvanced = true;
            }
            $normalized[] = $event;
        }

        if (!$hasTurnAdvanced && [] !== $normalized) {
            $out = [];
            $inserted = false;
            $maxSeq = 0;
            foreach ($normalized as $event) {
                $maxSeq = max($maxSeq, (int) ($event['seq'] ?? 0));
            }
            $anchor = $this->event($maxSeq + 1, 1, 'turn_advanced', ['turn_no' => 1]);
            foreach ($normalized as $event) {
                $out[] = $event;
                if (!$inserted && ($event['type'] ?? null) === 'run_started') {
                    $out[] = $anchor;
                    $inserted = true;
                }
            }
            $normalized = $out;
            $seq = 1;
            foreach ($normalized as &$event) {
                $event['seq'] = $seq;
                ++$seq;
            }
            unset($event);
        }

        $lines = [];
        foreach ($normalized as $event) {
            $lines[] = json_encode($event, \JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function event(int $seq, int $turnNo, string $type, array $payload = []): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => 'run-1',
            'seq' => $seq,
            'turn_no' => ('run_started' === $type ? 0 : $turnNo),
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }
}
