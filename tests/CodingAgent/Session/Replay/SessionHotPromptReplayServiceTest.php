<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Session\Replay\BranchReplayFilterContractAdapter;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use Ineersa\CodingAgent\Session\Replay\TurnTreeReplayFilter;
use Ineersa\CodingAgent\Session\TurnTree\TurnTreeProjector;
use PHPUnit\Framework\TestCase;

final class SessionHotPromptReplayServiceTest extends TestCase
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
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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

        $this->assertSame('canonical_events', $rebuiltState->source);
        $this->assertSame(2, $rebuiltState->lastSeq);
        $this->assertCount(2, $rebuiltState->messages);
        $this->assertTrue($rebuiltState->isContiguous);

        // Verify message contents.
        $messages = $rebuiltState->messages;
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('Hello', $messages[0]['content'][0]['text']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('Hi there!', $messages[1]['content'][0]['text']);
    }

    public function testCanonicalLlmStepCompletedWithToolCallsOnly(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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

        $this->assertCount(2, $rebuiltState->messages);
        $assistant = $rebuiltState->messages[1];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertSame([], $assistant['content'], 'null content becomes empty array');
        $this->assertArrayHasKey('metadata', $assistant);
        $this->assertArrayHasKey('tool_calls', $assistant['metadata']);
        $this->assertSame('call_1', $assistant['metadata']['tool_calls'][0]['id']);
        $this->assertSame('read', $assistant['metadata']['tool_calls'][0]['name']);
    }

    public function testCanonicalLlmStepCompletedWithThinkingDetails(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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

        $this->assertCount(2, $rebuiltState->messages);
        $assistant = $rebuiltState->messages[1];
        $this->assertSame('assistant', $assistant['role']);
        $this->assertArrayHasKey('details', $assistant);
        $this->assertSame('Let me think about this...', $assistant['details']['thinking']);
        $this->assertSame('sig123', $assistant['details']['thinking_signature']);
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
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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
        $this->assertCount(2, $rebuiltState->messages);

        // First message is the compact summary — metadata must survive.
        $compactMsg = $rebuiltState->messages[0];
        $this->assertSame('user', $compactMsg['role']);
        $this->assertSame('Summary of prior conversation...', $compactMsg['content'][0]['text']);
        $this->assertArrayHasKey('metadata', $compactMsg);
        $this->assertArrayHasKey('compact_summary', $compactMsg['metadata']);
        $this->assertTrue($compactMsg['metadata']['compact_summary']);

        // Second message is the retained tail.
        $this->assertSame('assistant', $rebuiltState->messages[1]['role']);
        $this->assertSame('First response', $rebuiltState->messages[1]['content'][0]['text']);
    }

    /**
     * Thesis: Later canonical assistant events append AFTER a replacement
     * checkpoint — the replacement does not prevent future canonical events
     * from being appended.
     */
    public function testCanonicalEventsAppendAfterReplacementCheckpoint(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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
        $this->assertCount(3, $rebuiltState->messages);

        // Replacement messages first.
        $this->assertSame('Compacted summary', $rebuiltState->messages[0]['content'][0]['text']);
        $this->assertTrue($rebuiltState->messages[0]['metadata']['compact_summary']);
        $this->assertSame('Old response', $rebuiltState->messages[1]['content'][0]['text']);

        // Newly appended canonical assistant message.
        $this->assertSame('assistant', $rebuiltState->messages[2]['role']);
        $this->assertSame('New response after compaction', $rebuiltState->messages[2]['content'][0]['text']);
    }

    // ── Existing tests updated to canonical event shapes ───────────────────────

    public function testRebuildUsesCanonicalEventsAndRestoresDeletedHotPromptState(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

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

        $this->assertSame('canonical_events', $rebuiltState->source);
        $this->assertSame(2, $rebuiltState->lastSeq);
        $this->assertCount(2, $rebuiltState->messages);
        $this->assertNotNull($hotPromptStore->get($runId));

        $hotPromptStore->delete($runId);
        $this->assertNull($hotPromptStore->get($runId));

        $rebuiltAfterDelete = $replayService->rebuildHotPromptState($runId);

        $this->assertSame($rebuiltState->messages, $rebuiltAfterDelete->messages);
        $this->assertNotNull($hotPromptStore->get($runId));

        $integrity = $replayService->verifyIntegrity($runId);
        $this->assertTrue($integrity->isContiguous);
        $this->assertSame([], $integrity->missingSequences);
    }

    public function testRebuildReturnsEmptyResultWhenNoEventsExist(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());

        $runId = 'run-no-events';

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        $this->assertSame('canonical_events', $rebuiltState->source);
        $this->assertSame(0, $rebuiltState->lastSeq);
        $this->assertCount(0, $rebuiltState->messages);
        $this->assertTrue($rebuiltState->isContiguous);
    }

    // ── Branch-aware prompt replay ──────────────────────────────────────────

    public function testBranchReplayExcludesAbandonedBranchMessages(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $treeFilter = new BranchReplayFilterContractAdapter(new TurnTreeReplayFilter(new TurnTreeProjector(), new \Ineersa\CodingAgent\Session\Replay\RewindBoundaryPolicy()));
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer(), null, null, $treeFilter);

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
        $this->assertSame(9, $rebuiltState->eventCount);
        $this->assertSame(9, $rebuiltState->lastSeq);
        $this->assertTrue($rebuiltState->isContiguous, 'Full canonical stream is contiguous');

        // Messages must only contain active-branch messages.
        $messageTexts = [];
        foreach ($rebuiltState->messages as $msg) {
            $messageTexts[] = $msg['content'][0]['text'] ?? '';
        }

        $this->assertContains('Hello', $messageTexts);
        $this->assertContains('Hi!', $messageTexts);
        $this->assertContains('ACTIVE response', $messageTexts);
        $this->assertNotContains('ABANDONED response', $messageTexts, 'Abandoned branch messages must be excluded');
    }

    // ── Context compaction hot prompt replay ──────────────────────────────────

    /**
     * Thesis: context_compacted replaces hot prompt messages from
     * payload.messages, and later events append on top of the replacement.
     * The ReplayService already handles payload.messages replacement
     * generically; this test proves context_compacted specifically.
     */
    public function testContextCompactedReplacesHotPromptMessages(): void
    {
        $eventStore = new InMemoryEventStore();
        $hotPromptStore = new HotPromptStateStore();
        $replayService = new SessionHotPromptReplayService($eventStore, $hotPromptStore, new PromptStateReplayService(), new ReplayEventPreparer());
        $runId = 'run-hot-prompt-compacted';

        // Original messages (3 user messages).
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::RunStarted->value, 1, 0, [
            'step_id' => 'init',
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Message 1']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Message 2']]],
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Message 3']]],
            ]],
        ]);

        // context_compacted replaces with 1 summary message.
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::ContextCompacted->value, 2, 0, [
            'summary_text' => 'Summary of first 3 messages',
            'messages' => [[
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => '<summary>Summary of first 3 messages</summary>']],
                'metadata' => ['compact_summary' => true],
            ]],
            'estimated_tokens_before' => 300,
            'estimated_tokens_after' => 100,
            'messages_compacted' => 3,
            'messages_retained' => 0,
            'first_retained_index' => 3,
            'model' => 'openai/gpt-4.1-mini',
        ]);

        // New user message after compaction.
        $this->appendTo($eventStore, $runId, RunEventTypeEnum::AgentCommandApplied->value, 3, 0, [
            'kind' => 'steer',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'New message after compaction']]],
        ]);

        $rebuiltState = $replayService->rebuildHotPromptState($runId);

        $this->assertCount(2, $rebuiltState->messages, 'Should have summary + new user message');
        $this->assertTrue(
            $rebuiltState->messages[0]['metadata']['compact_summary'] ?? false,
            'First message should be compact summary',
        );
        $this->assertSame(
            'New message after compaction',
            $rebuiltState->messages[1]['content'][0]['text'],
        );
    }

    // ── Helper ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function appendTo(InMemoryEventStore $store, string $runId, string $type, int $seq, int $turnNo, array $payload): void
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
