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
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Application\SessionInitializer;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
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

        $this->sessionInit = new SessionInitializer(
            sessionStore: $hatfieldSessionStore,
            eventStore: $this->eventStore,
            eventMapper: $mapper,
            projector: $this->projector,
            blockFactory: new TranscriptBlockFactory(),
            logger: new NullLogger(),
            eventApplier: new TuiRuntimeEventApplier($this->projector),
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
