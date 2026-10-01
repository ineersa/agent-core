<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use Ineersa\CodingAgent\Session\SessionHistoryProvider;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

/**
 * Thesis: ordinary runtime history consumers share one reconstructed projection and
 * never call EventStoreInterface::allFor / rangeFor / latestSequenceFor again.
 */
#[CoversClass(CacheHistoryProjectionStore::class)]
#[CoversClass(HistoryTailDiscardService::class)]
#[CoversClass(SessionHistoryProvider::class)]
final class HistoryProjectionRuntimeArchiveReadTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    public function testMetadataProjectionStreamsUnderStrict128M(): void
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/metadata-projection-memory.php'],
            env: ['HATFIELD_SESSION_ID' => false],
            timeout: 5,
        );
        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame('128M', $result['limit']);
            $this->assertSame(8194, $result['last_seq']);
            $this->assertSame('retained prompt', $result['prompt']);
            $this->assertSame([1], $result['turns']);
            $this->assertLessThan(128 * 1024 * 1024, $result['peak_bytes']);
            fwrite(\STDERR, 'metadata_projection peak_bytes='.$result['peak_bytes']." memory_limit=128M\n");
        } finally {
            $process->stop(0);
        }
    }

    public function testColdReconstructionStreamsStateHistoryAndTranscriptUnderStrict128M(): void
    {
        $process = new Process(
            [\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/cold-reconstruction-memory.php'],
            env: ['HATFIELD_SESSION_ID' => false],
            timeout: 8,
        );
        try {
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame('128M', $result['limit']);
            $this->assertGreaterThan(300, $result['last_seq']);
            $this->assertSame(3, $result['position']);
            $this->assertTrue($result['has_a']);
            $this->assertTrue($result['has_c']);
            $this->assertFalse($result['has_discarded']);
            $this->assertFalse($result['has_pending_launch']);
            $this->assertGreaterThan(0, $result['usage_input']);
            $this->assertGreaterThan(0, $result['usage_output']);
            $this->assertSame(1, $result['range_calls']);
            $this->assertSame(0, $result['all_for_calls']);
            $this->assertSame('completed', $result['terminal_reason']);
            $this->assertGreaterThan(50 * 1024 * 1024, $result['archive_bytes']);
            $this->assertLessThan(128 * 1024 * 1024, $result['peak_bytes']);
            fwrite(\STDERR, 'cold_reconstruction peak_bytes='.$result['peak_bytes'].' archive_bytes='.$result['archive_bytes']." memory_limit=128M\n");
        } finally {
            $process->stop(0);
        }
    }

    public function testSequenceHolesAreValidDuringInitializationAndCommittedUpdates(): void
    {
        $store = new CacheHistoryProjectionStore(new ArrayAdapter(), new LockFactory(new InMemoryStore()), new HistoryProjector(), new RunLockManager(new LockFactory(new InMemoryStore())));
        $runId = 'history-holes';
        $store->initializeFromEvents($runId, (static function () use ($runId): \Generator {
            yield new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'first']]]],
            ]);
            yield new RunEvent($runId, 5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]);
        })());
        $store->applyCommitted($runId, [
            new RunEvent($runId, 9, 2, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'next']),
        ]);
        $store->applyCommitted($runId, [
            new RunEvent($runId, 12, 3, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 3]),
        ]);
        $snapshot = $store->get($runId);
        $this->assertSame(12, $snapshot->lastSeq);
        $this->assertSame([2, 3], $snapshot->history->retainedTurnNos);
        $this->assertSame([2 => 'first', 3 => 'next'], $snapshot->history->promptsByTurnNo);
    }

    public function testColdInitializationSharedAndOrdinaryExecutionAvoidsArchiveReads(): void
    {
        $runId = 'history-projection-shared';
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('rangeFor');
        $eventStore->expects($this->never())->method('latestSequenceFor');
        $eventStore->expects($this->never())->method('append');
        $events = $this->seedLargeHistory($runId, turns: 40, payloadBytes: 4096);

        $store = new CacheHistoryProjectionStore(
            pool: new ArrayAdapter(),
            lockFactory: new LockFactory(new InMemoryStore()),
            projector: new HistoryProjector(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        $store->initializeFromEvents($runId, $events);
        $historyProvider = new SessionHistoryProvider($store);
        $view = $historyProvider->forSession($runId);
        $this->assertSame(40, $view->positionTurnNo);

        $shared = $store->get($runId);
        $this->assertSame(40, $shared->history->positionTurnNo);
        $lookupView = $historyProvider->forSession($runId);
        $this->assertSame($shared->history->positionTurnNo, $lookupView->positionTurnNo);

        $followUp = new RunEvent(
            runId: $runId,
            seq: $shared->lastSeq + 1,
            turnNo: 0,
            type: RunEventTypeEnum::AgentCommandApplied->value,
            payload: [
                'kind' => 'follow_up',
                'text' => 'pending follow-up',
            ],
        );
        $store->applyCommitted($runId, [$followUp]);
        $afterFollowUp = $store->get($runId);
        $this->assertSame('pending follow-up', $afterFollowUp->pendingHumanPrompt);

        $turn = new RunEvent(
            runId: $runId,
            seq: $afterFollowUp->lastSeq + 1,
            turnNo: 41,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: ['turn_no' => 41],
        );
        $store->applyCommitted($runId, [$turn]);
        $afterTurn = $store->get($runId);
        $this->assertNull($afterTurn->pendingHumanPrompt);
        $this->assertSame('pending follow-up', $afterTurn->history->promptsByTurnNo[41] ?? null);

        $discard = new HistoryTailDiscardService(
            $eventStore,
            $store,
            $this->sessionStore(),
            new NullLogger(),
            new \Ineersa\AgentCore\Tests\Support\TestActiveRunContext(),
            new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        $atTip = new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: [] === $afterTurn->history->retainedTurnNos ? 0 : $afterTurn->history->retainedTurnNos[array_key_last($afterTurn->history->retainedTurnNos)],
            lastSeq: $afterTurn->lastSeq,
        );
        $result = $discard->discardForwardTailIfNeeded($runId, $atTip);
        $this->assertFalse($result['discarded']);
    }

    public function testOlderHistoryPublicationKeepsStateAndHistoryCoherent(): void
    {
        $runId = 'cold-older-history';
        $store = new \Ineersa\AgentCore\Tests\Support\InMemoryEventStore();
        $historyStore = new InMemoryHistoryProjectionStore();
        $lockFactory = new LockFactory(new InMemoryStore());
        $runLock = new RunLockManager($lockFactory);
        $runStateStore = new \Ineersa\CodingAgent\Session\RunState\CacheRunStateStore(
            pool: new ArrayAdapter(),
            lockFactory: $lockFactory,
            serializer: \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::serializer(true),
            runLockManager: $runLock,
        );
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A']]]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 'a',
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer A']]],
            ]),
            new RunEvent($runId, 4, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'follow_up', 'text' => 'B']),
            new RunEvent($runId, 5, 2, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
            new RunEvent($runId, 6, 2, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 'b',
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer B']]],
            ]),
        ];
        foreach ($events as $event) {
            $store->seed($event);
        }

        $service = \Ineersa\CodingAgent\Tests\Support\SessionColdReconstructionTestFactory::create(
            $store,
            historyStore: $historyStore,
            runStateStore: $runStateStore,
        );
        $result = $service->reconstruct($runId, positionTurnNo: 1, publishSharedState: true, publishHistory: true);
        $this->assertSame(1, $result->runState->turnNo);
        $this->assertSame(6, $result->runState->lastSeq);
        $this->assertSame(1, $result->historySnapshot->history->positionTurnNo);
        $this->assertSame(6, $result->historySnapshot->lastSeq);
        $joined = implode("\n", array_map(static fn ($b) => $b->text, $result->transcript->transcriptBlocks));
        $this->assertStringContainsString('Answer A', $joined);
        $this->assertStringNotContainsString('Answer B', $joined);
        $this->assertSame(1, $historyStore->get($runId)->history->positionTurnNo);
        $this->assertSame(1, $runStateStore->get($runId)->turnNo);
        $this->assertSame(6, $runStateStore->get($runId)->lastSeq);
    }

    public function testInconsistentSourceOrderFailsClosedWithoutAdvancingCursor(): void
    {
        $runId = 'cold-inconsistent';
        $historyStore = new InMemoryHistoryProjectionStore();
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'A']]]],
            ]),
            new RunEvent($runId, 3, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 'a',
                'assistant_message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer A']]],
            ]),
        ];
        $store = new class($events) implements EventStoreInterface {
            /** @param list<RunEvent> $events */
            public function __construct(private array $events)
            {
            }

            public function append(RunEvent $event): RunEvent
            {
                throw new \RuntimeException('append not supported');
            }

            public function appendMany(array $events): array
            {
                throw new \RuntimeException('appendMany not supported');
            }

            public function latestSequenceFor(string $runId): ?int
            {
                return 3;
            }

            public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
            {
                foreach ($this->events as $event) {
                    if ($event->runId === $runId && $event->seq >= $startSeq && $event->seq <= $endSeq) {
                        yield $event;
                    }
                }
            }

            public function readAfterSeq(string $runId, int $cursor): array
            {
                throw new \RuntimeException('readAfterSeq not supported');
            }
        };

        $service = \Ineersa\CodingAgent\Tests\Support\SessionColdReconstructionTestFactory::create(
            $store,
            historyStore: $historyStore,
        );

        try {
            $service->reconstruct($runId, publishSharedState: true, publishHistory: true);
            $this->fail('Expected inconsistent source to fail closed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('canonical sequence order', $e->getMessage());
        }

        try {
            $historyStore->get($runId);
            $this->fail('Expected history projection to remain unpublished.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('History projection missing', $e->getMessage());
        }
    }

    public function testMissingProjectionFailsClosedWithoutArchiveReads(): void
    {
        $store = new CacheHistoryProjectionStore(
            pool: new ArrayAdapter(),
            lockFactory: new LockFactory(new InMemoryStore()),
            projector: new HistoryProjector(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        try {
            $store->get('missing-run');
            $this->fail('Expected missing projection to fail closed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('History projection missing', $e->getMessage());
        }
    }

    public function testInitializeFromEventsRejectsDuplicates(): void
    {
        $runId = 'history-projection-dup';
        $events = [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'start']],
                ]],
            ]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            new RunEvent($runId, 2, 1, RunEventTypeEnum::HistoryPositionSet->value, [
                'position_turn_no' => 1,
                'reason' => 'continue',
            ]),
        ];

        $store = new CacheHistoryProjectionStore(
            pool: new ArrayAdapter(),
            lockFactory: new LockFactory(new InMemoryStore()),
            projector: new HistoryProjector(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('duplicate sequence 2');
        $store->initializeFromEvents($runId, $events);
    }

    public function testApplyCommittedBootstrapsFromRunStartedWithoutArchiveReads(): void
    {
        $runId = 'history-projection-bootstrap';
        $store = new CacheHistoryProjectionStore(
            pool: new ArrayAdapter(),
            lockFactory: new LockFactory(new InMemoryStore()),
            projector: new HistoryProjector(),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
        );

        $store->applyCommitted($runId, [
            new RunEvent($runId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'hello']],
                ]],
            ]),
        ]);

        $snapshot = $store->get($runId);
        $this->assertSame(1, $snapshot->lastSeq);
        $this->assertSame('hello', $snapshot->initialPrompt);
    }

    /**
     * @return list<RunEvent>
     */
    private function seedLargeHistory(string $runId, int $turns, int $payloadBytes): array
    {
        $blob = str_repeat('x', $payloadBytes);
        $events = [];
        $events[] = new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'start']],
                ]],
            ],
        );
        $seq = 2;
        for ($turn = 1; $turn <= $turns; ++$turn) {
            $advanced = new RunEvent(
                runId: $runId,
                seq: $seq++,
                turnNo: $turn,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: ['turn_no' => $turn, 'blob' => $blob],
            );
            $completed = new RunEvent(
                runId: $runId,
                seq: $seq++,
                turnNo: $turn,
                type: RunEventTypeEnum::LlmStepCompleted->value,
                payload: ['blob' => $blob],
            );
            $events[] = $advanced;
            $events[] = $completed;
        }

        return $events;
    }

    private function sessionStore(): HatfieldSessionStore
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('history-projection');
        TestDirectoryIsolation::createHatfieldTree($this->tmpDir);

        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tmpDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new EventDispatcher(),
        );
    }
}
