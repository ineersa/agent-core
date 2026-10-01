<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\ActiveRunContext;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\Replay\SessionRunStateReplayService;
use Ineersa\CodingAgent\Session\RunState\CacheRunStateStore;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\SessionColdReconstructionTestFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Thesis: one cold reconstruction publishes ready shared projections; later
 * controller/runtime consumers reuse them without archive tip/range scans, and
 * an interrupted append/publication window cannot look healthy.
 */
#[CoversClass(CacheHistoryProjectionStore::class)]
#[CoversClass(CacheRunStateStore::class)]
#[CoversClass(ActiveRunContext::class)]
#[CoversClass(SessionRunStateReplayService::class)]
#[CoversClass(RunOperationalProjectionControllerSessionLifecycleListener::class)]
final class SharedStartupFreshnessIntegrationTest extends IsolatedKernelTestCase
{
    private string $workerScript;

    private string $workerDatabaseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workerScript = ProjectDir::get().'/tests/CodingAgent/Session/Replay/Support/SharedStartupCacheReuseKernelWorker.php';
        $this->workerDatabaseDir = TestDirectoryIsolation::createProjectTempDir('shared-startup-cache-db');
    }

    protected function tearDown(): void
    {
        try {
            TestDirectoryIsolation::removeDirectory($this->workerDatabaseDir);
        } finally {
            parent::tearDown();
        }
    }

    public function testColdStartupReusedByControllerAttachWithoutArchiveReads(): void
    {
        $runId = 'shared-startup-reuse';
        $pool = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $serializer = AttributeSerializerValidatorTestFactory::serializer(true);
        $runLock = new RunLockManager($locks);
        $historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
        $runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
        $projectionRepository = self::getContainer()->get('test.run_operational_projection_repository');
        $active = new ActiveRunContext($runStateStore, $projectionRepository, $runLock, $historyStore);

        $innerStore = new InMemoryEventStore();
        $this->seedDiscardedHistorySession($innerStore, $runId);
        $countingStore = new CountingEventStore($innerStore);

        $cold = SessionColdReconstructionTestFactory::create(
            eventStore: $countingStore,
            historyStore: $historyStore,
            activeRunContext: $active,
            runStateStore: $runStateStore,
        );
        $replay = new SessionRunStateReplayService(
            $countingStore,
            new NullLogger(),
            $cold,
            $historyStore,
            $runStateStore,
            $runLock,
        );

        $result = $cold->reconstruct(
            runId: $runId,
            publishSharedState: true,
            publishHistory: true,
        );
        $this->assertSame(1, $countingStore->rangeForCalls);
        $this->assertSame(0, $countingStore->latestSequenceForCalls);
        $this->assertSame(0, $countingStore->allForCalls);
        $this->assertSame(12, $result->lastSeq);
        $this->assertSame(3, $result->runState->turnNo);
        $this->assertTrue($historyStore->get($runId)->ready);
        $this->assertTrue($runStateStore->isReady($runId));

        $joined = implode("\n", array_map(
            static fn ($block): string => (string) ($block->text ?? ''),
            $result->transcript->transcriptBlocks,
        ));
        $this->assertStringContainsString('Answer A', $joined);
        $this->assertStringContainsString('Answer C active', $joined);
        $this->assertStringNotContainsString('Answer B discarded', $joined);

        // Controller attach lifecycle must keep ready shared projections and
        // republish operational identity for worker discovery / relationship reads.
        (new RunOperationalProjectionControllerSessionLifecycleListener($projectionRepository, $replay, $active))
            ->onSessionStarting(new ControllerSessionStartingEvent($runId));
        $this->assertSame(RunStatus::Running, $projectionRepository->findOperationalStatus($runId)?->status);
        $this->assertSame(12, $runStateStore->get($runId)->lastSeq);
        $this->assertSame(3, $historyStore->get($runId)->history->positionTurnNo);

        $beforeRange = $countingStore->rangeForCalls;
        $beforeLatest = $countingStore->latestSequenceForCalls;
        $beforeAll = $countingStore->allForCalls;

        $runtimeState = $active->stateFor($runId);
        $this->assertSame(12, $runtimeState->lastSeq);
        $this->assertSame(3, $runtimeState->turnNo);
        $this->assertSame('Answer C active', $this->assistantText($runtimeState));

        $queuedAttach = $replay->rebuildIfStale(RunState::queued($runId), $runId);
        $this->assertNotNull($queuedAttach->rebuiltState);
        $this->assertSame(12, $queuedAttach->rebuiltState->lastSeq);
        $this->assertSame(3, $queuedAttach->rebuiltState->turnNo);
        $this->assertSame($beforeRange, $countingStore->rangeForCalls);
        $this->assertSame($beforeLatest, $countingStore->latestSequenceForCalls);
        $this->assertSame($beforeAll, $countingStore->allForCalls);

        // Same authoritative seq with a stale caller turn must reuse ready state.
        $staleTurn = $runtimeState->with(['turnNo' => 0]);
        $sameSeqDifferentTurn = $replay->rebuildIfStale($staleTurn, $runId);
        $this->assertNotNull($sameSeqDifferentTurn->rebuiltState);
        $this->assertSame(12, $sameSeqDifferentTurn->rebuiltState->lastSeq);
        $this->assertSame(3, $sameSeqDifferentTurn->rebuiltState->turnNo);
        $this->assertSame($beforeRange, $countingStore->rangeForCalls);
        $this->assertSame($beforeLatest, $countingStore->latestSequenceForCalls);
        $this->assertSame($beforeAll, $countingStore->allForCalls);

        $current = $replay->rebuildIfStale($runtimeState, $runId);
        $this->assertNull($current->rebuiltState);
        $this->assertSame($beforeRange, $countingStore->rangeForCalls);
        $this->assertSame($beforeLatest, $countingStore->latestSequenceForCalls);
        $this->assertSame($beforeAll, $countingStore->allForCalls);

        // Attach republishes the operational row through the owning context.
        $active->remember($queuedAttach->rebuiltState);
        $this->assertSame(
            RunStatus::Running,
            $projectionRepository->findOperationalStatus($runId)?->status,
        );
    }

    public function testPersistentCacheReuseAcrossOwnedSubprocessesWithoutArchiveAccess(): void
    {
        $runId = 'shared-startup-persistent-cache';
        $publish = $this->runCacheWorker(['publish', $runId]);
        $this->assertSame(0, $publish['exit'], 'publish worker stderr: '.$publish['stderr']);
        $published = json_decode($publish['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($published);
        $this->assertSame(12, $published['last_seq']);
        $this->assertSame(3, $published['turn_no']);
        $this->assertTrue($published['ready_state']);
        $this->assertTrue($published['ready_history']);

        $reuse = $this->runCacheWorker(['reuse', $runId]);
        $this->assertSame(0, $reuse['exit'], 'reuse worker stderr: '.$reuse['stderr']);
        $reused = json_decode($reuse['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($reused);
        $this->assertSame(12, $reused['last_seq']);
        $this->assertSame(3, $reused['turn_no']);
        $this->assertSame(0, $reused['range_for_calls']);
        $this->assertSame(0, $reused['latest_sequence_for_calls']);
        $this->assertSame(0, $reused['all_for_calls']);
    }

    public function testInterruptedAppendRejectsStaleReadyPairAndRecoveryPublishesOnce(): void
    {
        $runId = 'shared-startup-interrupted';
        $pool = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $serializer = AttributeSerializerValidatorTestFactory::serializer(true);
        $runLock = new RunLockManager($locks);
        $historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
        $runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
        $projectionRepository = self::getContainer()->get('test.run_operational_projection_repository');
        $active = new ActiveRunContext($runStateStore, $projectionRepository, $runLock, $historyStore);

        $innerStore = new InMemoryEventStore();
        $this->seedDiscardedHistorySession($innerStore, $runId);
        $countingStore = new CountingEventStore($innerStore);

        $cold = SessionColdReconstructionTestFactory::create(
            eventStore: $countingStore,
            historyStore: $historyStore,
            activeRunContext: $active,
            runStateStore: $runStateStore,
        );
        $replay = new SessionRunStateReplayService(
            $countingStore,
            new NullLogger(),
            $cold,
            $historyStore,
            $runStateStore,
            $runLock,
        );

        $cold->reconstruct($runId, publishSharedState: true, publishHistory: true);
        $this->assertSame(1, $countingStore->rangeForCalls);

        $failingActive = new FailAfterAppendActiveRunContext($active);
        $commandBus = new TestMessageBus();
        $appender = new CommittedRunEventAppender(
            eventStore: $countingStore,
            commandBus: $commandBus,
            activeRunContext: $failingActive,
            runStateReducer: new RunStateReducer(
                AttributeSerializerValidatorTestFactory::denormalizer(),
                new \Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec(
                    AttributeSerializerValidatorTestFactory::serializer(),
                ),
            ),
            runLockManager: $runLock,
            historyProjectionStore: $historyStore,
        );

        try {
            $appender->append(new RunEvent(
                runId: $runId,
                type: RunEventTypeEnum::AgentEnd->value,
                seq: 13,
                turnNo: 3,
                payload: ['reason' => 'completed'],
                createdAt: new \DateTimeImmutable('2026-09-30T00:00:13+00:00'),
            ));
            $this->fail('production appender must fail after durable append before publication');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated publication failure after durable append', $exception->getMessage());
        }

        $this->assertSame(13, $countingStore->latestSequenceFor($runId));
        $this->assertFalse($runStateStore->isReady($runId));
        try {
            $active->stateFor($runId);
            $this->fail('ordinary lookup must reject jointly withdrawn projections');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }

        $before = $countingStore->rangeForCalls;
        $rebuilt = $replay->rebuildIfStale(RunState::queued($runId), $runId);
        $this->assertNotNull($rebuilt->rebuiltState);
        $this->assertSame(13, $rebuilt->rebuiltState->lastSeq);
        $this->assertSame($before + 1, $countingStore->rangeForCalls);
        $this->assertTrue($runStateStore->isReady($runId));
        $this->assertTrue($historyStore->get($runId)->ready);
        $this->assertSame(13, $active->stateFor($runId)->lastSeq);
    }

    public function testRunCommitInterruptedPublicationLeavesDurableEventNotReady(): void
    {
        $runId = 'shared-startup-runcommit-interrupted';
        $pool = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $serializer = AttributeSerializerValidatorTestFactory::serializer(true);
        $runLock = new RunLockManager($locks);
        $historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
        $runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
        $projectionRepository = self::getContainer()->get('test.run_operational_projection_repository');
        $active = new ActiveRunContext($runStateStore, $projectionRepository, $runLock, $historyStore);

        $innerStore = new InMemoryEventStore();
        $this->seedDiscardedHistorySession($innerStore, $runId);
        $countingStore = new CountingEventStore($innerStore);
        $cold = SessionColdReconstructionTestFactory::create(
            eventStore: $countingStore,
            historyStore: $historyStore,
            activeRunContext: $active,
            runStateStore: $runStateStore,
        );
        $cold->reconstruct($runId, publishSharedState: true, publishHistory: true);

        $failingActive = new FailAfterAppendActiveRunContext($active);
        $commit = new RunCommit(
            activeRunContext: $failingActive,
            eventStore: $countingStore,
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new NullLogger(),
            historyProjectionMaintainer: $historyStore,
        );

        $current = $active->stateFor($runId);
        $next = $current->with([
            'status' => RunStatus::Completed,
            'lastSeq' => 13,
        ]);

        try {
            $commit->commit($current, $next, [
                new RunEvent(
                    runId: $runId,
                    type: RunEventTypeEnum::AgentEnd->value,
                    seq: 13,
                    turnNo: 3,
                    payload: ['reason' => 'completed'],
                    createdAt: new \DateTimeImmutable('2026-09-30T00:00:13+00:00'),
                ),
            ]);
            $this->fail('RunCommit must fail after durable append before publication');
        } catch (\RuntimeException $exception) {
            $this->assertSame('simulated publication failure after durable append', $exception->getMessage());
        }

        $this->assertSame(13, $countingStore->latestSequenceFor($runId));
        $this->assertFalse($runStateStore->isReady($runId));
        try {
            $active->stateFor($runId);
            $this->fail('ordinary lookup must reject jointly withdrawn projections');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not ready', $e->getMessage());
        }
    }

    public function testRecoveryPublicationDoesNotClobberNewerCommit(): void
    {
        $runId = 'shared-startup-noclobber';
        $pool = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $serializer = AttributeSerializerValidatorTestFactory::serializer(true);
        $runLock = new RunLockManager($locks);
        $historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
        $runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
        $projectionRepository = self::getContainer()->get('test.run_operational_projection_repository');
        $active = new ActiveRunContext($runStateStore, $projectionRepository, $runLock, $historyStore);

        $innerStore = new InMemoryEventStore();
        $this->seedDiscardedHistorySession($innerStore, $runId);
        $countingStore = new CountingEventStore($innerStore);
        $cold = SessionColdReconstructionTestFactory::create(
            eventStore: $countingStore,
            historyStore: $historyStore,
            activeRunContext: $active,
            runStateStore: $runStateStore,
        );

        $cold->reconstruct($runId, publishSharedState: true, publishHistory: true);
        $countingStore->append(new RunEvent(
            runId: $runId,
            type: RunEventTypeEnum::AgentEnd->value,
            seq: 13,
            turnNo: 3,
            payload: ['reason' => 'completed'],
            createdAt: new \DateTimeImmutable('2026-09-30T00:00:13+00:00'),
        ));
        $active->remember(new RunState($runId, RunStatus::Completed, turnNo: 3, lastSeq: 13));
        $historyStore->applyCommitted($runId, [
            new RunEvent(
                runId: $runId,
                type: RunEventTypeEnum::AgentEnd->value,
                seq: 13,
                turnNo: 3,
                payload: ['reason' => 'completed'],
                createdAt: new \DateTimeImmutable('2026-09-30T00:00:13+00:00'),
            ),
        ]);
        $this->assertSame(13, $historyStore->get($runId)->lastSeq);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('newer shared');
        $cold->reconstruct($runId, publishSharedState: true, publishHistory: true, knownMaxSeq: 12);
    }

    public function testDuplicateBootstrapPreservesReadyStateAndHistory(): void
    {
        $runId = 'shared-startup-bootstrap';
        $pool = new ArrayAdapter();
        $locks = new LockFactory(new InMemoryStore());
        $serializer = AttributeSerializerValidatorTestFactory::serializer(true);
        $runLock = new RunLockManager($locks);
        $historyStore = new CacheHistoryProjectionStore($pool, $locks, new HistoryProjector(), $runLock);
        $runStateStore = new CacheRunStateStore($pool, $locks, $serializer, $runLock);
        $projectionRepository = self::getContainer()->get('test.run_operational_projection_repository');
        $active = new ActiveRunContext(
            $runStateStore,
            $projectionRepository,
            $runLock,
            $historyStore,
        );

        $first = $active->initializeQueued($runId);
        $second = $active->initializeQueued($runId);
        $this->assertSame(0, $first->lastSeq);
        $this->assertSame(0, $second->lastSeq);
        $this->assertSame(0, $historyStore->get($runId)->lastSeq);
        $this->assertTrue($runStateStore->isReady($runId));
        $this->assertTrue($historyStore->get($runId)->ready);
    }

    /**
     * @param list<string> $args
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCacheWorker(array $args): array
    {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open(
            array_merge([\PHP_BINARY, $this->workerScript], $args),
            $spec,
            $pipes,
            ProjectDir::get(),
            $this->cacheWorkerEnv(),
        );
        $this->assertIsResource($proc, 'cache worker must start');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + 8.0;
        $stdout = '';
        $stderr = '';
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            if (!$status['running']) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($proc);

                return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
            }
            usleep(5_000);
        }

        proc_terminate($proc);
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr."\nworker timed out"];
    }

    /** @return array<string, string> */
    private function cacheWorkerEnv(): array
    {
        $relativeDatabaseDir = '../tmp/'.basename($this->workerDatabaseDir);

        return array_merge($_ENV, [
            'APP_ENV' => 'test',
            'HATFIELD_CWD' => $this->isolatedCwd(),
            'HATFIELD_TEST_DATABASE_PATH' => $relativeDatabaseDir.'/state.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $relativeDatabaseDir.'/messenger-transport.sqlite',
        ]);
    }

    private function assistantText(RunState $state): string
    {
        foreach (array_reverse($state->messages) as $message) {
            if ('assistant' !== $message->role) {
                continue;
            }
            foreach ($message->content as $part) {
                if (\is_array($part) && ($part['type'] ?? null) === 'text' && \is_string($part['text'] ?? null)) {
                    return $part['text'];
                }
            }
        }

        return '';
    }

    private function seedDiscardedHistorySession(InMemoryEventStore $store, string $runId): void
    {
        $events = [
            $this->event($runId, 'run_started', 1, 0, ['payload' => ['messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Prompt A']]],
            ]]]),
            $this->event($runId, 'turn_advanced', 2, 1, ['turn_no' => 1]),
            $this->event($runId, 'history_position_set', 3, 1, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => null,
                'reason' => 'continue',
            ]),
            $this->event($runId, 'llm_step_completed', 4, 1, $this->assistantPayload('Answer A', 'step-a')),
            $this->event($runId, 'turn_advanced', 5, 2, ['turn_no' => 2]),
            $this->event($runId, 'history_position_set', 6, 2, [
                'position_turn_no' => 2,
                'previous_position_turn_no' => 1,
                'reason' => 'continue',
            ]),
            $this->event($runId, 'llm_step_completed', 7, 2, $this->assistantPayload('Answer B discarded', 'step-b')),
            $this->event($runId, 'history_position_set', 8, 1, [
                'position_turn_no' => 1,
                'previous_position_turn_no' => 2,
                'reason' => 'history_select',
            ]),
            $this->event($runId, RunEventTypeEnum::HistoryTailDiscarded->value, 9, 1, ['after_turn_no' => 1]),
            $this->event($runId, 'turn_advanced', 10, 3, ['turn_no' => 3]),
            $this->event($runId, 'history_position_set', 11, 3, [
                'position_turn_no' => 3,
                'previous_position_turn_no' => 1,
                'reason' => 'continue',
            ]),
            $this->event($runId, 'llm_step_completed', 12, 3, $this->assistantPayload('Answer C active', 'step-c')),
        ];
        foreach ($events as $event) {
            $store->seed($event);
        }
    }

    /** @return array<string, mixed> */
    private function assistantPayload(string $text, string $stepId): array
    {
        return [
            'step_id' => $stepId,
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function event(string $runId, string $type, int $seq, int $turnNo, array $payload): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(\sprintf('2026-09-30T00:00:%02d+00:00', $seq)),
        );
    }
}

/**
 * Delegates until remember()/initialize(), then fails so production append/commit
 * paths leave a durable event with withdrawn projections.
 */
final class FailAfterAppendActiveRunContext implements \Ineersa\AgentCore\Contract\ActiveRunContextInterface
{
    public function __construct(
        private readonly ActiveRunContext $inner,
    ) {
    }

    public function stateFor(string $runId): RunState
    {
        return $this->inner->stateFor($runId);
    }

    public function remember(RunState $state): void
    {
        throw new \RuntimeException('simulated publication failure after durable append');
    }

    public function initializeQueued(string $runId): RunState
    {
        return $this->inner->initializeQueued($runId);
    }

    public function initialize(RunState $state): void
    {
        throw new \RuntimeException('simulated publication failure after durable append');
    }

    public function applyCommittedSuffix(string $runId, array $events, callable $advance): RunState
    {
        throw new \RuntimeException('simulated publication failure after durable append');
    }

    public function invalidate(string $runId): void
    {
        $this->inner->invalidate($runId);
    }

    public function withdrawForCommit(string $runId): void
    {
        $this->inner->withdrawForCommit($runId);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }
}

final class CountingEventStore implements EventStoreInterface
{
    public int $allForCalls = 0;
    public int $latestSequenceForCalls = 0;
    public int $rangeForCalls = 0;

    public function __construct(private readonly InMemoryEventStore $inner)
    {
    }

    public function append(RunEvent $event): RunEvent
    {
        return $this->inner->append($event);
    }

    public function appendMany(array $events): array
    {
        return $this->inner->appendMany($events);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        ++$this->latestSequenceForCalls;

        return $this->inner->latestSequenceFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        ++$this->rangeForCalls;

        yield from $this->inner->rangeFor($runId, $startSeq, $endSeq);
    }

    public function readAfterSeq(string $runId, int $cursor): array
    {
        $events = [];
        foreach ($this->rangeFor($runId, 1, \PHP_INT_MAX) as $event) {
            if ($event->seq > $cursor) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
