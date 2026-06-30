<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Application\SessionInitializer;
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
    private SessionRunEventStore $eventStore;
    private SessionInitializer $sessionInit;
    private TranscriptProjectorInterface $projector;

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
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        // Collect blocks accepted by the real projector pattern: the mock
        // tracks accept() calls so we can assert projection behaviour.
        $this->projector = $this->createMock(TranscriptProjectorInterface::class);

        $this->eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
        );

        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );

        $turnTreeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $turnTreeProvider->method('forSession')->willReturn(new TurnTreeView(
            runId: 'test',
            nodesByTurnNo: [],
            rootTurnNos: [],
            currentLeafTurnNo: null,
            activePathTurnNos: [],
        ));

        $this->sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            eventMapper: $mapper,
            projector: $this->projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            eventApplier: new TuiRuntimeEventApplier($this->projector),
            turnTreeProvider: $turnTreeProvider,
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
        $this->projector->expects(self::never())->method('reset');

        $state = new TuiSessionState('test-fresh', false);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        self::assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
    }

    public function testReplayFromEmptyEventsReturnsFallback(): void
    {
        $runId = 'run-empty-'.bin2hex(random_bytes(4));
        // Create session dir so SessionRunEventStore can read empty events.jsonl
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        $this->projector->expects(self::once())->method('reset');

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        self::assertStringContainsString('no messages yet', $blocks[0]->text);
    }

    public function testReplayFromEventsSetsLastSeqAndReturnsProjectedBlocks(): void
    {
        $runId = 'run-replay-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        // Append steer command event that maps to user.message_submitted
        $steerEvent = new RunEvent(
            runId: $runId,
            seq: 5,
            turnNo: 0,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_abc123',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Hello from replayed steer']],
                ],
            ],
        );
        $this->eventStore->append($steerEvent);

        // Append a dropped event (e.g. tool_batch_committed) that maps to null
        $droppedEvent = new RunEvent(
            runId: $runId,
            seq: 7,
            turnNo: 0,
            type: 'tool_batch_committed',
        );
        $this->eventStore->append($droppedEvent);

        // Projector mock: track accepted events and return one block
        $acceptedEvents = [];
        $projectedBlock = new TranscriptBlock(
            id: 'user_'.$runId.'_5_ik_abc123',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $runId,
            seq: 5,
            text: 'Hello from replayed steer',
        );

        $this->projector->expects(self::once())->method('reset');
        $this->projector->expects(self::exactly(1))
            ->method('accept')
            ->willReturnCallback(static function (array $event) use (&$acceptedEvents): void {
                $acceptedEvents[] = $event;
            });
        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([$projectedBlock]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        // One block projected (steer), one dropped (tool_batch_committed)
        self::assertCount(1, $acceptedEvents);
        self::assertSame('user.message_submitted', $acceptedEvents[0]['type']);
        self::assertSame('Hello from replayed steer', $acceptedEvents[0]['payload']['text']);

        // lastSeq = max(5 mapped, 7 source) = 7
        self::assertSame(7, $state->lastSeq);

        // Blocks returned are from the projector
        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
        self::assertStringContainsString('Hello from replayed steer', $blocks[0]->text);
    }

    public function testReplayAllDroppedEventsSetsLastSeqFromSourceSeq(): void
    {
        $runId = 'run-alldropped-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        // Append only events that get dropped by the mapper
        $droppedEvent = new RunEvent(
            runId: $runId,
            seq: 3,
            turnNo: 0,
            type: 'agent_command_queued',
        );
        $this->eventStore->append($droppedEvent);

        $this->projector->expects(self::once())->method('reset');
        $this->projector->expects(self::never())->method('accept');
        $this->projector->expects(self::once())
            ->method('blocks')
            ->willReturn([]);

        $state = new TuiSessionState($runId, true);
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        // All events dropped by mapper, projector returned no blocks → fallback
        self::assertSame(3, $state->lastSeq);
        self::assertCount(1, $blocks);
        self::assertStringContainsString('no messages yet', $blocks[0]->text);
    }

    // ── initializeDraft (lazy draft sessions) ────────────────────────

    public function testInitializeDraftReturnsEmptySessionId(): void
    {
        // Draft init is pure in-memory — no projector interaction.
        $this->projector->expects(self::never())->method('reset');
        $this->projector->expects(self::never())->method('accept');

        $state = $this->sessionInit->initializeDraft();

        self::assertSame('', $state->sessionId);
        self::assertFalse($state->resuming);
        self::assertNull($state->request);
        self::assertNull($state->handle);
    }

    public function testInitializeDraftWithRequestPreservesRequest(): void
    {
        $this->projector->expects(self::never())->method('reset');
        $this->projector->expects(self::never())->method('accept');

        $request = new StartRunRequest(prompt: '', runId: '', model: 'gpt-4');
        $state = $this->sessionInit->initializeDraft($request);

        self::assertSame('', $state->sessionId);
        self::assertSame($request, $state->request);
    }

    public function testBuildInitialTranscriptForDraftReturnsWelcome(): void
    {
        // Draft sessions never enter the replay path, so projector is unused.
        $this->projector->expects(self::never())->method('reset');
        $this->projector->expects(self::never())->method('accept');

        $state = $this->sessionInit->initializeDraft();
        $blocks = $this->sessionInit->buildInitialTranscript($state);

        self::assertCount(1, $blocks);
        self::assertSame(TranscriptBlockKindEnum::System, $blocks[0]->kind);
        self::assertStringContainsString('Welcome to Hatfield', $blocks[0]->text);
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
        $this->projector->expects(self::never())->method('reset');
        $this->projector->expects(self::never())->method('accept');

        $stateRequest = null;
        $sessionId = 'promo-test-42';
        $text = 'Hello from draft';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd ?? '',
            options: $stateRequest->options ?? [],
            model: $stateRequest?->model,
            reasoning: $stateRequest?->reasoning,
        );

        self::assertSame('Hello from draft', $request->prompt);
        self::assertSame('promo-test-42', $request->runId);
        self::assertSame('', $request->cwd);
        self::assertSame([], $request->options);
        self::assertNull($request->model);
        self::assertNull($request->reasoning);
    }

    /**
     * Companion guard: when /new --model gpt-4 sets state->request with
     * configured values, the merged request must carry them forward while
     * using the user-typed prompt text.
     */
    public function testDraftPromotionStartRunRequestPreservesDraftValues(): void
    {
        // Does not touch projector — pure DTO construction test.
        $this->projector->expects(self::never())->method('reset');
        $this->projector->expects(self::never())->method('accept');

        $stateRequest = new StartRunRequest(
            prompt: 'stale',
            runId: '',
            cwd: '/custom/path',
            options: ['foo' => 'bar'],
            model: 'gpt-4',
            reasoning: 'high',
        );
        $sessionId = 'promo-test-43';
        $text = 'Real user message';

        $request = new StartRunRequest(
            prompt: $text,
            runId: $sessionId,
            cwd: $stateRequest->cwd,
            options: $stateRequest->options,
            model: $stateRequest->model,
            reasoning: $stateRequest->reasoning,
        );

        self::assertSame('Real user message', $request->prompt);
        self::assertSame('promo-test-43', $request->runId);
        self::assertSame('/custom/path', $request->cwd);
        self::assertSame(['foo' => 'bar'], $request->options);
        self::assertSame('gpt-4', $request->model);
        self::assertSame('high', $request->reasoning);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBranchAwareResumeFiltersOutAbandonedBranchBlocks(): void
    {
        // Thesis: when the session has a known currentLeafTurnNo (has been
        // rewound), replayFromEvents filters to the active path, excluding
        // abandoned-branch blocks from the transcript.  lastSeq is set from
        // the FULL canonical stream, not regressed.

        $runId = 'run-branch-'.bin2hex(random_bytes(4));
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($sessionDir, 0777, true);
        file_put_contents($sessionDir.'/events.jsonl', '');

        // ── Events: linear (T1, T2) → LeafSet(rewind to T1) → T3 (new branch) ──
        // Turn 1 (active, seq 5)
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 5,
            turnNo: 1,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t1',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 1']]],
            ],
        ));
        // Turn 2 (abandoned branch, seq 8)
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 8,
            turnNo: 2,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t2',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 2 — abandoned']]],
            ],
        ));
        // LeafSet (rewind to T1, seq 12)
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 12,
            turnNo: 1,
            type: 'leaf_set',
            payload: ['turn_no' => 1, 'previous_turn_no' => 2],
        ));
        // Turn 3 (active new branch, seq 15)
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: 15,
            turnNo: 3,
            type: 'agent_command_applied',
            payload: [
                'kind' => 'steer',
                'idempotency_key' => 'ik_t3',
                'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Turn 3 — new branch']]],
            ],
        ));

        // ── TurnTreeProvider providing the branch-aware filtering ──
        $turnTreeProvider = $this->createMock(TurnTreeProviderInterface::class);

        // forSession returns tree with currentLeafTurnNo = 1 (rewound from T2 back to T1)
        $turnTreeProvider->expects(self::once())
            ->method('forSession')
            ->with($runId)
            ->willReturn(new TurnTreeView(
                runId: $runId,
                nodesByTurnNo: [],
                rootTurnNos: [1],
                currentLeafTurnNo: 1,
                activePathTurnNos: [1, 3],
            ));

        // activePathRuntimeEvents returns filtered events (only T1 + T3)
        $turnTreeProvider->expects(self::once())
            ->method('activePathRuntimeEvents')
            ->with($runId, 1)
            ->willReturn([
                new RuntimeEvent(
                    type: 'user.message_submitted',
                    runId: $runId,
                    seq: 5,
                    payload: ['text' => 'Turn 1', 'message_id' => 'msg_t1'],
                ),
                new RuntimeEvent(
                    type: 'user.message_submitted',
                    runId: $runId,
                    seq: 15,
                    payload: ['text' => 'Turn 3 — new branch', 'message_id' => 'msg_t3'],
                ),
            ]);

        // ── Build a fresh initializer with real projector + custom provider ──
        $projector = $this->buildRealTranscriptProjector();

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );

        $sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            eventMapper: $mapper,
            projector: $projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            eventApplier: new TuiRuntimeEventApplier($projector),
            turnTreeProvider: $turnTreeProvider,
        );

        $state = new TuiSessionState($runId, true);
        $blocks = $sessionInit->buildInitialTranscript($state);

        // Active-path events only: T1 + T3 = 2 blocks
        self::assertCount(2, $blocks, 'Only active-path blocks should appear');
        self::assertStringContainsString('Turn 1', $blocks[0]->text);
        self::assertStringContainsString('Turn 3', $blocks[1]->text);

        // lastSeq must be the max from the FULL canonical stream (seq 15 = T3)
        self::assertSame(15, $state->lastSeq, 'lastSeq must reflect full stream max, not just filtered events');
    }

    /**
     * Build a real TranscriptProjector for integration testing.
     *
     * @return TranscriptProjectorInterface
     */
    private function buildRealTranscriptProjector(): TranscriptProjectorInterface
    {
        $dispatcher = new EventDispatcher();
        $projectionState = new \Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState();
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new \Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber());

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
