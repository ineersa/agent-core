<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * End-to-end resume integration tests using the real projection chain.
 *
 * Verifies that SessionInitializer::buildInitialTranscript() replays
 * canonical events.jsonl through RuntimeEventMapper → TranscriptProjector
 * and produces the expected TranscriptBlock DTOs with correct lastSeq
 * and activity state.
 *
 * Uses a real TranscriptProjector with all projection subscribers to
 * validate the full resume replay path end-to-end.
 *
 * @covers \Ineersa\Tui\Application\SessionInitializer
 */
#[CoversClass(SessionInitializer::class)]
final class SessionInitializerReplayTest extends TestCase
{
    private string $projectDir = '';
    private SessionRunEventStore $eventStore;
    private SessionInitializer $sessionInit;
    private TranscriptProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-replay-test-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: self::createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $this->eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
        );

        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );

        // Real projector with all projection subscribers — no mocking.
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new CancellationProjectionSubscriber());
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());
        $this->projector = new TranscriptProjector($dispatcher, $state);

        $this->sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            eventMapper: $mapper,
            projector: $this->projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    // ── User + assistant conversation ──────────────────────────────────────

    public function testReplayUserAssistantConversation(): void
    {
        $runId = 'run-conversation-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // run_started with user prompt
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello!']]],
                ],
            ],
        ]);

        // llm_step_completed with assistant response
        $this->append($runId, 2, 'llm_step_completed', [
            'step_id' => 'step-2',
            'text' => 'Hi there! How can I help?',
        ]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        // Should have: UserMessage + AssistantMessage
        self::assertGreaterThanOrEqual(2, \count($blocks), 'Expected at least 2 blocks');

        $userBlocks = array_filter($blocks, static fn ($b) => TranscriptBlockKindEnum::UserMessage === $b->kind);
        self::assertCount(1, $userBlocks, 'Expected 1 UserMessage block');
        $userBlock = array_values($userBlocks)[0];
        self::assertStringContainsString('Hello!', $userBlock->text);

        $assistantBlocks = array_filter($blocks, static fn ($b) => TranscriptBlockKindEnum::AssistantMessage === $b->kind);
        self::assertCount(1, $assistantBlocks, 'Expected 1 AssistantMessage block');
        $assistantBlock = array_values($assistantBlocks)[0];
        self::assertStringContainsString('Hi there!', $assistantBlock->text);

        // lastSeq = 2 (max persistent source seq)
        self::assertSame(2, $state->lastSeq);

        // Activity: Running (run was in-progress at event seq=2)
        self::assertSame(RunActivityStateEnum::Running, $state->activity);

        // No replayed blocks should be left in streaming state
        foreach ($blocks as $block) {
            self::assertFalse($block->streaming, \sprintf(
                'Block %s should not be streaming after replay',
                $block->kind->value,
            ));
        }
    }

    // ── Tool + HITL sequence ───────────────────────────────────────────────

    public function testReplayToolAndHitlSequence(): void
    {
        $runId = 'run-tool-hitl-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // 1: run_started
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Read a file']]],
                ],
            ],
        ]);

        // 2: llm_step_completed (assistant with tool calls)
        $this->append($runId, 2, 'llm_step_completed', [
            'step_id' => 'step-2',
            'text' => 'Let me read that file.',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Let me read that file.']],
            ],
        ]);

        // 3: tool_execution_start
        $this->append($runId, 3, 'tool_execution_start', [
            'tool_call_id' => 'tc-1',
            'tool_name' => 'read',
            'order_index' => 0,
        ]);

        // 4: tool_execution_end
        $this->append($runId, 4, 'tool_execution_end', [
            'tool_call_id' => 'tc-1',
            'is_error' => false,
            'order_index' => 0,
        ]);

        // 5: waiting_human (HITL question)
        $this->append($runId, 5, 'waiting_human', [
            'question_id' => 'q-1',
            'prompt' => 'Proceed with the read?',
        ]);

        // 6: agent_command_applied (human_response)
        $this->append($runId, 6, 'agent_command_applied', [
            'kind' => 'human_response',
            'question_id' => 'q-1',
            'answer' => 'yes',
        ]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        // Verify block kinds appear in order
        $kinds = array_map(static fn ($b) => $b->kind, $blocks);

        self::assertContains(TranscriptBlockKindEnum::UserMessage, $kinds);
        self::assertContains(TranscriptBlockKindEnum::AssistantMessage, $kinds);
        // Tool execution events (tool_execution.start/completed) produce ToolResult blocks.
        // ToolCall blocks are transient-only (streaming seq=0, not in canonical events.jsonl).
        self::assertContains(TranscriptBlockKindEnum::ToolResult, $kinds);
        self::assertContains(TranscriptBlockKindEnum::Question, $kinds);

        // lastSeq = 6
        self::assertSame(6, $state->lastSeq);

        // Activity: Running (human_input.answered transitioned to Running)
        self::assertSame(RunActivityStateEnum::Running, $state->activity);
    }

    // ── Cancellation resume ────────────────────────────────────────────────

    public function testReplayCancellation(): void
    {
        $runId = 'run-cancel-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // 1: run_started
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Do something long']]],
                ],
            ],
        ]);

        // 2: agent_command_applied (cancel)
        $this->append($runId, 2, 'agent_command_applied', [
            'kind' => 'cancel',
        ]);

        // 3: agent_end (cancelled)
        $this->append($runId, 3, 'agent_end', [
            'reason' => 'cancelled',
        ]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        $kinds = array_map(static fn ($b) => $b->kind, $blocks);

        self::assertContains(TranscriptBlockKindEnum::UserMessage, $kinds);
        // agent_command_applied(kind=cancel) → cancellation.requested (marker, no block)
        // agent_end(reason=cancelled) → run.cancelled → Cancelled block
        self::assertContains(TranscriptBlockKindEnum::Cancelled, $kinds);

        // lastSeq = 3
        self::assertSame(3, $state->lastSeq);

        // Activity: Cancelled (terminal state from run.cancelled)
        self::assertSame(RunActivityStateEnum::Cancelled, $state->activity);
    }

    // ── Error resume ───────────────────────────────────────────────────────

    public function testReplayError(): void
    {
        $runId = 'run-error-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // 1: run_started
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Crash test']]],
                ],
            ],
        ]);

        // 2: llm_step_failed
        $this->append($runId, 2, 'llm_step_failed', [
            'step_id' => 'step-2',
            'error' => ['message' => 'LLM worker timeout'],
        ]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        $kinds = array_map(static fn ($b) => $b->kind, $blocks);

        // Should have UserMessage and at least one error-related block
        self::assertContains(TranscriptBlockKindEnum::UserMessage, $kinds);

        $hasError = \in_array(TranscriptBlockKindEnum::Error, $kinds, true)
            || \in_array(TranscriptBlockKindEnum::System, $kinds, true);
        self::assertTrue($hasError, 'Expected error or system block for failed step');

        // lastSeq = 2
        self::assertSame(2, $state->lastSeq);

        // Activity: Failed
        self::assertSame(RunActivityStateEnum::Failed, $state->activity);
    }

    // ── Dedup: lastSeq prevents re-processing after resume ─────────────────

    public function testReplaySetsLastSeqSoPollerSkippedOldEvents(): void
    {
        $runId = 'run-dedup-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // Append conversation events at seq 1-2
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'First message']]],
                ],
            ],
        ]);

        $this->append($runId, 2, 'llm_step_completed', [
            'step_id' => 'step-2',
            'text' => 'First response',
        ]);

        // Replay session
        $state = new TuiSessionState($runId, true);
        $initialBlocks = $this->sessionInit->buildInitialTranscript($state);

        self::assertSame(2, $state->lastSeq, 'lastSeq should be max source seq after replay');

        // Now simulate the poller: append a new event at seq 3, and verify
        // that events at seq ≤ 2 are skipped (they would be by the poller's
        // dedup logic).  We verify this by checking that re-replaying old
        // events through the same projector does not change the block count
        // — projector blocks are idempotent by ID.
        $blockCountAfterReplay = \count($this->projector->blocks());

        // Append a new steer event at seq 3
        $this->append($runId, 3, 'agent_command_applied', [
            'kind' => 'steer',
            'idempotency_key' => 'ik_new',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Follow-up message']],
            ],
        ]);

        // Simulate the live poller: construct a fresh mapper+translator pair
        // independent from the one used during SessionInitializer replay.
        // This mirrors how the poller creates its own mapping chain per tick
        // rather than sharing state with the initial replay path.
        $pollerMapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );

        // Re-read events and feed only the new one (simulating poller dedup)
        $allEvents = $this->eventStore->allFor($runId);
        $newBlocks = 0;
        foreach ($allEvents as $runEvent) {
            if ($runEvent->seq <= $state->lastSeq) {
                continue; // Would be skipped by poller
            }
            $runtimeEvent = $pollerMapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent) {
                $this->projector->accept($runtimeEvent->toArray());
                ++$newBlocks;
            }
        }

        self::assertSame(1, $newBlocks, 'Expected exactly one new mapped event at seq 3.');

        $blocksAfterNewEvent = $this->projector->blocks();
        self::assertGreaterThan(
            $blockCountAfterReplay,
            \count($blocksAfterNewEvent),
            'New event at seq > lastSeq should add blocks',
        );

        // lastSeq reflects the max persistent seq replayed (2). The poller
        // would see seq 3 as new and advance its own cursor, but state->lastSeq
        // is only set during SessionInitializer replay, not here.
        self::assertSame(2, $state->lastSeq);
    }

    // ── Activity state from resume replay ──────────────────────────────────

    public function testReplayRestoresWaitingHumanActivity(): void
    {
        $runId = 'run-waiting-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // 1: run_started
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Approve this']]],
                ],
            ],
        ]);

        // 2: waiting_human (last event)
        $this->append($runId, 2, 'waiting_human', [
            'question_id' => 'q-approve',
            'prompt' => 'Approve action?',
        ]);

        $state = new TuiSessionState($runId, true);
        $this->sessionInit->buildInitialTranscript($state);

        // Activity should be WaitingHuman after last event
        self::assertSame(RunActivityStateEnum::WaitingHuman, $state->activity);
    }

    // ── Dropped/null-mapped events ─────────────────────────────────────────

    public function testReplayAdvancesLastSeqForDroppedEvents(): void
    {
        $runId = 'run-dropped-'.bin2hex(random_bytes(4));
        $this->ensureSessionDir($runId);

        // 1: run_started (mapped → UserMessage block)
        $this->append($runId, 1, 'run_started', [
            'step_id' => 'step-1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
                ],
            ],
        ]);

        // 2: tool_batch_committed (DROPPED by RuntimeEventTranslator — no mapped event)
        $this->append($runId, 2, 'tool_batch_committed', []);

        // 3: agent_command_queued (DROPPED by RuntimeEventTranslator)
        $this->append($runId, 3, 'agent_command_queued', [
            'kind' => 'steer',
            'idempotency_key' => 'ik-drop',
        ]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        // lastSeq must be 3 (max source seq), not 1 (max mapped seq).
        // This prevents the live poller from re-processing dropped events.
        self::assertSame(3, $state->lastSeq, 'lastSeq must advance to max source seq even when events are dropped');

        // The mapped events still produce at least the UserMessage block
        $kinds = array_map(static fn ($b) => $b->kind, $blocks);
        self::assertContains(TranscriptBlockKindEnum::UserMessage, $kinds);
        self::assertNotEmpty($blocks, 'Mapped events should produce at least some blocks');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function ensureSessionDir(string $runId): void
    {
        $dir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        // Ensure empty events.jsonl exists so SessionRunEventStore can append
        if (!file_exists($dir.'/events.jsonl')) {
            file_put_contents($dir.'/events.jsonl', '');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(string $runId, int $seq, string $type, array $payload = []): void
    {
        $event = new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: 0,
            type: $type,
            payload: $payload,
        );
        $this->eventStore->append($event);
    }

    /**
     * Recursively remove a directory.
     */
    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($dir);
    }
}
