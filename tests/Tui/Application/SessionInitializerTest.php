<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Ineersa\CodingAgent\Session\SessionHistoryProvider;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Session\SessionTranscriptProvider;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(SessionInitializer::class)]
final class SessionInitializerTest extends TestCase
{
    private string $projectDir = '';
    private TuiRuntimeEventApplier $eventApplier;
    private SessionRunEventStore $eventStore;
    private SessionInitializer $sessionInit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-session-init-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class)
        );

        $this->eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator()
        );

        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher())
        );

        $transcriptProjector = $this->buildRealTranscriptProjector();
        $historyProvider = new SessionHistoryProvider($this->eventStore, new HistoryProjector());
        $sessionTranscriptProvider = new SessionTranscriptProvider(
            eventStore: $this->eventStore,
            replayFilter: new HistoryReplayFilter(new HistoryProjector()),
            eventMapper: $mapper,
            transcriptProjector: $transcriptProjector
        );

        $this->eventApplier = new TuiRuntimeEventApplier($transcriptProjector, SubagentProgressSerializerTestSupport::denormalizer());

        $this->sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: $historyProvider,
            sessionTranscriptProvider: $sessionTranscriptProvider
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    public function testBuildInitialTranscriptFreshSessionReturnsWelcome(): void
    {
        // Fresh session does not call projector, but PHPUnit
        // expects mock expectations on setUp-managed mocks.

        $state = new TuiSessionState('test-fresh', false);
        $blocks = $this->sessionInit->buildInitialTranscript($state, $this->eventApplier);

        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
    }

    public function testReplayFromEmptyEventsReturnsFallback(): void
    {
        $runId = 'run-empty-'.bin2hex(random_bytes(4));
        // Create session dir so SessionRunEventStore can read empty events.jsonl
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state, $this->eventApplier);

        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('no messages yet', $blocks[0]->text);
    }

    public function testReplayFromEventsSetsLastSeqAndReturnsProjectedBlocks(): void
    {
        $runId = 'run-replay-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [
                'payload' => ['messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Hello from replayed steer']],
                ]]],
            ]
        ));
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 5,
            turnNo: 1,
            type: 'turn_advanced',
            payload: ['turn_no' => 1]
        ));
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 6,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_abc123',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Hello from replayed steer']],
                ],
            ]
        ));
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 7,
            turnNo: 0,
            type: 'tool_batch_committed'
        ));

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state, $this->eventApplier);

        $this->assertSame(7, $state->lastSeq);
        $found = false;
        foreach ($blocks as $block) {
            if (str_contains($block->text, 'Hello from replayed steer')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Retained transcript must include the human prompt text');
    }

    public function testReplayAllDroppedEventsSetsLastSeqFromSourceSeq(): void
    {
        $runId = 'run-alldropped-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 3,
            turnNo: 0,
            type: 'agent_command_queued'
        ));

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state, $this->eventApplier);

        $this->assertSame(3, $state->lastSeq);
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString('no messages yet', $blocks[0]->text);
    }

    // ── initializeDraft (lazy draft sessions) ────────────────────────

    public function testInitializeDraftReturnsEmptySessionId(): void
    {
        // Draft init is pure in-memory — no projector interaction.

        $state = $this->sessionInit->initializeDraft();

        $this->assertSame('', $state->sessionId);
        $this->assertFalse($state->resuming);
        $this->assertNull($state->request);
        $this->assertNull($state->handle);
    }

    public function testInitializeDraftWithRequestPreservesRequest(): void
    {
        $request = new StartRunRequest(prompt: '', runId: '', model: 'gpt-4');
        $state = $this->sessionInit->initializeDraft($request);

        $this->assertSame('', $state->sessionId);
        $this->assertSame($request, $state->request);
    }

    public function testBuildInitialTranscriptForDraftReturnsWelcome(): void
    {
        // Draft sessions never enter the replay path, so projector is unused.

        $state = $this->sessionInit->initializeDraft();
        $blocks = $this->sessionInit->buildInitialTranscript($state, $this->eventApplier);

        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        $this->assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
    }

    // ── Draft promotion request construction ─────────────────────────

    /**
     * Guards the SubmitListener draft promotion code path at line ~119:
     * when $state->request is null (plain /new with no model/options/cwd),
     * cwd and options must default to '' and [] — StartRunRequest rejects
     * null for these non-nullable typed properties.
     */
    public function testDraftPromotionStartRunRequestNullDefaultsDoNotTypeError(): void
    {
        // Does not touch projector — this is a pure DTO construction test.

        $stateRequest = null;
        $sessionId = 'promo-test-42';
        $text = 'Hello from draft';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd ?? '',
            options: $stateRequest->options ?? [],
            model: $stateRequest?->model,
            reasoning: $stateRequest?->reasoning
        );

        $this->assertSame('Hello from draft', $request->prompt);
        $this->assertSame('promo-test-42', $request->runId);
        $this->assertSame('', $request->cwd);
        $this->assertSame([], $request->options);
        $this->assertNull($request->model);
        $this->assertNull($request->reasoning);
    }

    /**
     * Companion guard: when /new --model gpt-4 sets state->request with
     * configured values, the merged request must carry them forward while
     * using the user-typed prompt text.
     */
    public function testDraftPromotionStartRunRequestPreservesDraftValues(): void
    {
        // Does not touch projector — pure DTO construction test.

        $stateRequest = new StartRunRequest(
            prompt: 'stale',
            runId: '',
            cwd: '/custom/path',
            options: ['foo' => 'bar'],
            model: 'gpt-4',
            reasoning: 'high'
        );
        $sessionId = 'promo-test-43';
        $text = 'Real user message';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd,
            options: $stateRequest->options,
            model: $stateRequest->model,
            reasoning: $stateRequest->reasoning
        );

        $this->assertSame('Real user message', $request->prompt);
        $this->assertSame('promo-test-43', $request->runId);
        $this->assertSame('/custom/path', $request->cwd);
        $this->assertSame(['foo' => 'bar'], $request->options);
        $this->assertSame('gpt-4', $request->model);
        $this->assertSame('high', $request->reasoning);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRetainedHistoryResumeFiltersOutDiscardedTailBlocks(): void
    {
        // Thesis: when the session has a known positionTurnNo (has been
        // selected earlier), replayFromEvents filters to the retained prefix, excluding
        // discarded-tail blocks from the transcript.  lastSeq is set from
        // the FULL canonical stream, not regressed.

        $runId = 'run-history-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        // ── Events: linear (T1, T2) → history_position_set(select T1) → T3 (new tail) ──
        // Turn 1 (active, seq 5)
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 5,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t1',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 1']]],
            ]
        ));
        // Turn 2 (later discarded, seq 8)
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 8,
            turnNo: 2,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t2',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 2 — discarded']]],
            ]
        ));
        // history_position_set (select T1, seq 12)
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 12,
            turnNo: 1,
            type: 'history_position_set',
            payload: ['position_turn_no' => 1, 'previous_position_turn_no' => 2]
        ));
        // Turn 3 (active new tail, seq 15)
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 15,
            turnNo: 3,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t3',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 3 — new tail']]],
            ]
        ));

        // ── HistoryProvider providing selected position ──
        $historyProvider = $this->createMock(HistoryProviderInterface::class);

        // forSession returns history with positionTurnNo = 1 (selected from T2 back to T1)
        $historyProvider->expects($this->once())
            ->method('forSession')
            ->with($runId)
            ->willReturn(new HistoryView(prompts: [], positionTurnNo: 1));

        // transcriptAtPosition returns projected blocks (only T1 + T3)
        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with($runId, 1)
            ->willReturn(new SessionTranscriptSnapshotDTO(
                transcriptBlocks: [
                    new TranscriptBlock(id: 'b1', kind: TranscriptBlockKindEnum::UserMessage, runId: $runId, seq: 5, text: 'Turn 1'),
                    new TranscriptBlock(id: 'b3', kind: TranscriptBlockKindEnum::UserMessage, runId: $runId, seq: 15, text: 'Turn 3 — new tail'),
                ],
                replayEvents: []
            ));

        // ── Build a fresh initializer with real projector + custom provider ──
        $projector = $this->buildRealTranscriptProjector();

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class)
        );
        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher())
        );

        $eventApplier = new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer());
        $sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: $historyProvider,
            sessionTranscriptProvider: $sessionTranscriptProvider
        );

        $state = new TuiSessionState($runId, true);
        $blocks = $sessionInit->buildInitialTranscript($state, $eventApplier);

        // Retained-history events only: T1 + T3 = 2 blocks
        $this->assertCount(2, $blocks, 'Only retained-history blocks should appear');
        $this->assertStringContainsString('Turn 1', $blocks[0]->text);
        $this->assertStringContainsString('Turn 3', $blocks[1]->text);

        // lastSeq must be the max from the FULL canonical stream (seq 15 = T3)
        $this->assertSame(15, $state->lastSeq, 'lastSeq must reflect full stream max, not just filtered events');
    }

    public function testResumeInfersCancelledActivityFromLatestAgentEnd(): void
    {
        $runId = 'run-terminal-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'follow_up',
                'idempotency_key' => 'ik_follow',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ]
        ));
        $this->seedCanonicalEvent(new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: 1,
            type: 'agent_end',
            payload: ['reason' => 'cancelled']
        ));

        $historyProvider = $this->createMock(HistoryProviderInterface::class);
        $historyProvider->expects($this->once())
            ->method('forSession')
            ->with($runId)
            ->willReturn(new HistoryView(prompts: [], positionTurnNo: 1));
        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->once())
            ->method('transcriptAtPosition')
            ->with($runId, 1)
            ->willReturn(new SessionTranscriptSnapshotDTO(
                transcriptBlocks: [new TranscriptBlock(id: 'b1', kind: TranscriptBlockKindEnum::UserMessage, runId: $runId, seq: 1, text: 'Hello')],
                replayEvents: []
            ));

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class)
        );
        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher())
        );

        $eventApplier = new TuiRuntimeEventApplier($this->buildRealTranscriptProjector(), SubagentProgressSerializerTestSupport::denormalizer());
        $sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: $historyProvider,
            sessionTranscriptProvider: $sessionTranscriptProvider
        );

        $state = new TuiSessionState($runId, true);
        $sessionInit->buildInitialTranscript($state, $eventApplier);

        $this->assertSame(RunActivityStateEnum::Cancelled, $state->activity);
        $this->assertSame(2, $state->lastSeq);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testProviderFailureDoesNotFullReplayDiscardedTail(): void
    {
        $runId = 'run-failclosed-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');
        $this->seedCanonicalEvent(new RunEvent(runId: $runId, seq: 1, turnNo: 1, type: 'agent_command_applied', payload: [
            'kind' => 'steer', 'idempotency_key' => 'ik',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'should not appear via full fallback']]],
        ]));

        $historyProvider = $this->createMock(HistoryProviderInterface::class);
        $historyProvider->method('forSession')->willThrowException(new \RuntimeException('history unavailable'));

        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->expects($this->never())->method('transcriptAtPosition');

        [$sessionInit, $eventApplier] = $this->buildSessionInitializerWithProviders($historyProvider, $sessionTranscriptProvider);
        $state = new TuiSessionState($runId, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('history unavailable');
        $sessionInit->buildInitialTranscript($state, $eventApplier);
    }

    public function testRetainedHistoryResumeUsesCanonicalLastSeqNotTranscriptBlockSeq(): void
    {
        $runId = 'run-lastseq-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->seedCanonicalEvent(new RunEvent(runId: $runId, seq: 5, turnNo: 1, type: 'agent_command_applied', payload: [
            'kind' => 'steer', 'idempotency_key' => 'ik_t1',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 1']]],
        ]));
        $this->seedCanonicalEvent(new RunEvent(runId: $runId, seq: 99, turnNo: 2, type: 'agent_command_applied', payload: [
            'kind' => 'steer', 'idempotency_key' => 'ik_discarded',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Abandoned']]],
        ]));

        $historyProvider = $this->createStub(HistoryProviderInterface::class);
        $historyProvider->method('forSession')->willReturn(new HistoryView(prompts: [], positionTurnNo: 1));

        $sessionTranscriptProvider = $this->createStub(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->method('transcriptAtPosition')->willReturn(new SessionTranscriptSnapshotDTO(
            transcriptBlocks: [
                new TranscriptBlock(id: 'b1', kind: TranscriptBlockKindEnum::UserMessage, runId: $runId, seq: 1, text: 'Turn 1'),
            ],
            replayEvents: []
        ));

        [$sessionInit, $eventApplier] = $this->buildSessionInitializerWithProviders($historyProvider, $sessionTranscriptProvider);
        $state = new TuiSessionState($runId, true);
        $sessionInit->buildInitialTranscript($state, $eventApplier);

        $this->assertSame(99, $state->lastSeq, 'lastSeq must use canonical stream max, not TranscriptBlock::seq');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRetainedHistoryResumeReconstructsUsageFromProviderReplayEvents(): void
    {
        $runId = 'run-usage-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->seedCanonicalEvent(new RunEvent(runId: $runId, seq: 1, turnNo: 1, type: 'run_started', payload: [
            'step_id' => 'step-1',
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]]]],
        ]));
        $this->seedCanonicalEvent(new RunEvent(runId: $runId, seq: 2, turnNo: 1, type: 'llm_step_completed', payload: [
            'step_id' => 'step-2', 'text' => 'Hello', 'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
        ]));

        $historyProvider = $this->createMock(HistoryProviderInterface::class);
        $historyProvider->method('forSession')->willReturn(new HistoryView(prompts: [], positionTurnNo: 1));

        $usageEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: $runId,
            seq: 2,
            payload: ['text' => 'Hello', 'usage' => ['input_tokens' => 120, 'output_tokens' => 30]]
        );

        $sessionTranscriptProvider = $this->createMock(SessionTranscriptProviderInterface::class);
        $sessionTranscriptProvider->method('transcriptAtPosition')->willReturn(new SessionTranscriptSnapshotDTO(
            transcriptBlocks: [
                new TranscriptBlock(id: 'b1', kind: TranscriptBlockKindEnum::AssistantMessage, runId: $runId, seq: 1, text: 'Hello'),
            ],
            replayEvents: [$usageEvent]
        ));

        $projector = $this->buildRealTranscriptProjector();
        [$sessionInit, $eventApplier] = $this->buildSessionInitializerWithProviders($historyProvider, $sessionTranscriptProvider, $projector);
        $state = new TuiSessionState($runId, true);
        $sessionInit->buildInitialTranscript($state, $eventApplier);

        $this->assertSame(120, $state->usage->latestInputTokens);
        $this->assertSame(120, $state->usage->inputTokens);
        $this->assertSame(30, $state->usage->outputTokens);
    }

    /**
     * Seed events.jsonl with an explicit seq (historical/gap scenarios). Production append always allocates.
     */
    private function seedCanonicalEvent(RunEvent $event): void
    {
        $path = $this->projectDir.'/.hatfield/sessions/'.$event->runId.'/events.jsonl';
        $normalizer = new EventPayloadNormalizer();
        $json = json_encode($normalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR);
        file_put_contents($path, $json."\n", \FILE_APPEND);
        $counterPath = FileRunSequenceAllocator::counterPathForEventsLog($path);
        $current = is_readable($counterPath) ? (int) trim((string) file_get_contents($counterPath)) : 0;
        if ($event->seq > $current) {
            file_put_contents($counterPath, (string) $event->seq."\n");
        }
    }

    /**
     * @return array{SessionInitializer, TuiRuntimeEventApplier}
     */
    private function buildSessionInitializerWithProviders(
        HistoryProviderInterface $historyProvider,
        SessionTranscriptProviderInterface $sessionTranscriptProvider,
        ?TranscriptProjectorInterface $projector = null,
    ): array {
        $projector ??= $this->buildRealTranscriptProjector();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class)
        );
        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));
        $eventApplier = new TuiRuntimeEventApplier($projector, SubagentProgressSerializerTestSupport::denormalizer());

        return [new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            historyProvider: $historyProvider,
            sessionTranscriptProvider: $sessionTranscriptProvider
        ), $eventApplier];
    }

    /**
     * Build a real TranscriptProjector for integration testing.
     */
    private function buildRealTranscriptProjector(): TranscriptProjectorInterface
    {
        $dispatcher = new EventDispatcher();
        $projectionState = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(new SubagentProgressDisplayFormatter(), SubagentProgressSerializerTestSupport::denormalizer()));

        return new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector($dispatcher, $projectionState);
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
            \RecursiveIteratorIterator::CHILD_FIRST
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
