<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use PHPUnit\Framework\TestCase;

final class ReplayServiceTest extends TestCase
{
    // ── Canonical llm_step_completed replay ────────────────────────────────────

    /**
     * Thesis: A canonical llm_step_completed event with payload.assistant_message
     * appends an assistant message into rebuilt hot prompt state.
     * Without the production fix (payload.assistant_message handling in
     * ReplayService), this fails because replayMessages() would skip the
     * event entirely — it previously handled payload.assistant (legacy
     * string for assistant output) and payload.message (used by production
     * AgentCommandApplied user-message events, but not by canonical
     * llm_step_completed assistant output), not the canonical
     * payload.assistant_message emitted by LlmStepResultHandler.
     */
    public function testCanonicalLlmStepCompletedAppendsAssistantMessage(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-canonical-llm-step';

        // Start message (replacement via payload.messages).
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                ]],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));

        // Canonical llm_step_completed event from LlmStepResultHandler.
        // payload.assistant_message carries the normalized assistant
        // message structure (role, content, tool_calls, details).
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'tool_calls_count' => 0,
                'text' => 'Hi there!',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Hi there!']],
                ],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:01:00+00:00'),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState->source);
        self::assertSame(2, $rebuiltState->lastSeq);
        self::assertCount(2, $rebuiltState->messages);
        self::assertTrue($rebuiltState->isContiguous);

        // Verify message contents.
        $messages = $rebuiltState->messages;
        self::assertSame('user', $messages[0]['role']);
        self::assertSame('Hello', $messages[0]['content'][0]['text']);
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame('Hi there!', $messages[1]['content'][0]['text']);
    }

    public function testCanonicalLlmStepCompletedWithToolCallsOnly(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-canonical-tool-calls';

        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Read file.txt']],
                ]],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'stop_reason' => 'tool_calls',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
                'tool_calls_count' => 1,
                'text' => null,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'name' => 'read',
                        'arguments' => ['path' => './file.txt'],
                        'order_index' => 0,
                    ]],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertCount(2, $rebuiltState->messages);
        $assistant = $rebuiltState->messages[1];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame([], $assistant['content'], 'null content becomes empty array');
        self::assertArrayHasKey('metadata', $assistant);
        self::assertArrayHasKey('tool_calls', $assistant['metadata']);
        self::assertSame('call_1', $assistant['metadata']['tool_calls'][0]['id']);
        self::assertSame('read', $assistant['metadata']['tool_calls'][0]['name']);
    }

    public function testCanonicalLlmStepCompletedWithThinkingDetails(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-canonical-thinking';

        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Question']],
                ]],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 15],
                'tool_calls_count' => 0,
                'text' => 'Answer',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Answer']],
                    'details' => [
                        'thinking' => 'Let me think about this...',
                        'thinking_signature' => 'sig123',
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertCount(2, $rebuiltState->messages);
        $assistant = $rebuiltState->messages[1];
        self::assertSame('assistant', $assistant['role']);
        self::assertArrayHasKey('details', $assistant);
        self::assertSame('Let me think about this...', $assistant['details']['thinking']);
        self::assertSame('sig123', $assistant['details']['thinking_signature']);
    }

    // ── Message-list replacement semantics ─────────────────────────────────────

    /**
     * Thesis: A later event with payload.messages replaces previously
     * accumulated messages rather than appending. This is the semantic
     * that future context_compacted events will rely on.
     *
     * We use a documented fixture event type string
     * ('context_compacted') to prove the generic payload.messages
     * replacement behaviour without introducing a production
     * RunEventTypeEnum case ahead of COMP-02.
     */
    public function testMessagesPayloadReplacesPreviousMessages(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-replacement';

        // Turn 1: initial messages.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Initial prompt']],
                ]],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
                'tool_calls_count' => 0,
                'text' => 'First response',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'First response']],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        // Now we have 2 messages: user + assistant.

        // "Compaction" event: replaces the full message list.
        // Uses a documented fixture event type, not a production enum.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 3,
            turnNo: 2,
            type: 'context_compacted',
            payload: [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => 'Summary of prior conversation...']],
                        'metadata' => ['compact_summary' => true],
                    ],
                    [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'First response']],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        // After replacement, only 2 messages (the replacement set).
        self::assertCount(2, $rebuiltState->messages);

        // First message is the compact summary — metadata must survive.
        $compactMsg = $rebuiltState->messages[0];
        self::assertSame('user', $compactMsg['role']);
        self::assertSame('Summary of prior conversation...', $compactMsg['content'][0]['text']);
        self::assertArrayHasKey('metadata', $compactMsg);
        self::assertArrayHasKey('compact_summary', $compactMsg['metadata']);
        self::assertTrue($compactMsg['metadata']['compact_summary']);

        // Second message is the retained tail.
        self::assertSame('assistant', $rebuiltState->messages[1]['role']);
        self::assertSame('First response', $rebuiltState->messages[1]['content'][0]['text']);
    }

    /**
     * Thesis: Later canonical assistant events append AFTER a replacement
     * checkpoint — the replacement does not prevent future canonical events
     * from being appended.
     */
    public function testCanonicalEventsAppendAfterReplacementCheckpoint(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-append-after-replacement';

        // Initial.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Initial']],
                ]],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        // Full replacement (compaction checkpoint with documented fixture type).
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: 'context_compacted',
            payload: [
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [['type' => 'text', 'text' => 'Compacted summary']],
                        'metadata' => ['compact_summary' => true],
                    ],
                    [
                        'role' => 'assistant',
                        'content' => [['type' => 'text', 'text' => 'Old response']],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        // New canonical llm_step_completed appends after replacement.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 3,
            turnNo: 2,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's2',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 8, 'output_tokens' => 4],
                'tool_calls_count' => 0,
                'text' => 'New response after compaction',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'New response after compaction']],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        // 2 from replacement + 1 new canonical = 3 messages.
        self::assertCount(3, $rebuiltState->messages);

        // Replacement messages first.
        self::assertSame('Compacted summary', $rebuiltState->messages[0]['content'][0]['text']);
        self::assertTrue($rebuiltState->messages[0]['metadata']['compact_summary']);
        self::assertSame('Old response', $rebuiltState->messages[1]['content'][0]['text']);

        // Newly appended canonical assistant message.
        self::assertSame('assistant', $rebuiltState->messages[2]['role']);
        self::assertSame('New response after compaction', $rebuiltState->messages[2]['content'][0]['text']);
    }

    // ── Existing tests updated to canonical event shapes ───────────────────────

    public function testRebuildUsesCanonicalEventsAndRestoresDeletedHotPromptState(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-replay-canonical';
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hello',
                    ]],
                ]],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));
        // Use canonical llm_step_completed event instead of synthetic
        // 'assistant_message' event with payload.message.
        $eventStore->append(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::LlmStepCompleted->value,
            payload: [
                'step_id' => 's1',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
                'tool_calls_count' => 0,
                'text' => 'Hi!',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'Hi!',
                    ]],
                ],
            ],
            createdAt: new \DateTimeImmutable('2026-04-12T12:01:00+00:00'),
        ));

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState->source);
        self::assertSame(2, $rebuiltState->lastSeq);
        self::assertCount(2, $rebuiltState->messages);
        self::assertNotNull($hotPromptStore->get($runId));

        $hotPromptStore->delete($runId);
        self::assertNull($hotPromptStore->get($runId));

        $rebuiltAfterDelete = $replayService->rebuildHotPromptState($runId);

        self::assertSame($rebuiltState->messages, $rebuiltAfterDelete->messages);
        self::assertNotNull($hotPromptStore->get($runId));

        $integrity = $replayService->verifyIntegrity($runId);
        self::assertTrue($integrity->isContiguous);
        self::assertSame([], $integrity->missingSequences);
    }

    public function testRebuildReturnsEmptyResultWhenNoEventsExist(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService($eventStore, $hotPromptStore);

        $runId = 'run-no-events';

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        self::assertSame('canonical_events', $rebuiltState->source);
        self::assertSame(0, $rebuiltState->lastSeq);
        self::assertCount(0, $rebuiltState->messages);
        self::assertTrue($rebuiltState->isContiguous);
    }

    // ── Branch-aware prompt replay ──────────────────────────────────────────

    public function testBranchReplayExcludesAbandonedBranchMessages(): void
    {
        $eventStore = new RunEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new ReplayService(
            $eventStore,
            $hotPromptStore,
            turnTreeReplayFilter: new TurnTreeReplayFilter(new TurnTreeProjector()),
        );

        $runId = 'run-branch-replay';

        // Turn 1: initial
        $this->appendTo($eventStore, $runId, 'run_started', 1, 0, [
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Hello']],
            ]],
        ]);
        // Turn 1 assistant — canonical llm_step_completed.
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LlmStepCompleted->value, 2, 1, [
            'step_id' => 's1',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            'tool_calls_count' => 0,
            'text' => 'Hi!',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
            ],
        ]);

        // Turn 2: follow-up (ABANDONED branch)
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::TurnAdvanced->value, 3, 2, [
            'turn_no' => 2, 'parent_turn_no' => 1, 'step_id' => 's2',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 4, 2, [
            'turn_no' => 2, 'parent_turn_no' => 1, 'reason' => 'continue',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LlmStepCompleted->value, 5, 2, [
            'step_id' => 's2',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            'tool_calls_count' => 0,
            'text' => 'ABANDONED response',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ABANDONED response']],
            ],
        ]);

        // Rewind to turn 1, branch turn 3 (ACTIVE)
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 6, 1, [
            'turn_no' => 1, 'reason' => 'rewind',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::TurnAdvanced->value, 7, 3, [
            'turn_no' => 3, 'parent_turn_no' => 1, 'step_id' => 's3',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LeafSet->value, 8, 3, [
            'turn_no' => 3, 'parent_turn_no' => 1, 'reason' => 'continue',
        ]);
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::LlmStepCompleted->value, 9, 3, [
            'step_id' => 's3',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
            'tool_calls_count' => 0,
            'text' => 'ACTIVE response',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ACTIVE response']],
            ],
        ]);

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        // Integrity must describe the full canonical stream.
        self::assertSame(9, $rebuiltState->eventCount);
        self::assertSame(9, $rebuiltState->lastSeq);
        self::assertTrue($rebuiltState->isContiguous, 'Full canonical stream is contiguous');

        // Messages must only contain active-branch messages.
        $messageTexts = [];
        foreach ($rebuiltState->messages as $msg) {
            $messageTexts[] = $msg['content'][0]['text'] ?? '';
        }

        self::assertContains('Hello', $messageTexts);
        self::assertContains('Hi!', $messageTexts);
        self::assertContains('ACTIVE response', $messageTexts);
        self::assertNotContains('ABANDONED response', $messageTexts, 'Abandoned branch messages must be excluded');
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function appendTo(RunEventStore $store, string $runId, string $type, int $seq, int $turnNo, array $payload): void
    {
        $store->append(new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        ));
    }
}
