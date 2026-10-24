<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ToolBatchSnapshotCleanupHookSubscriberTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('tool-batch-cleanup-hook');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testDeletesExactBatchAfterToolBatchCommitted(): void
    {
        $store = $this->createStore();
        $finalized = new ToolBatchStateDTO([], [], [], [], [], true, 2);
        $this->seedBatch($store, 'run-1', 3, 'step-x', $finalized);
        $this->seedBatch($store, 'run-1', 3, 'step-other', new ToolBatchStateDTO([], [], [], [], [], false, 2));

        $subscriber = $this->subscriber($store);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 3,
            status: RunStatus::Running->value,
            events: [
                new AfterTurnCommitEventSummary(10, RunEventTypeEnum::ToolBatchCommitted->value, [
                    'count' => 1,
                    'turn_no' => 3,
                    'step_id' => 'step-x',
                ]),
            ],
            effectsCount: 0,
            runState: new RunState('run-1', RunStatus::Running, turnNo: 3),
        ));

        $this->assertNull($store->load('run-1', 3, 'step-x'));
        $this->assertNotNull($store->load('run-1', 3, 'step-other'));
    }

    public function testTerminalAgentEndDeletesAllRemainingSnapshots(): void
    {
        $store = $this->createStore();
        $this->seedBatch($store, 'run-1', 1, 's1', new ToolBatchStateDTO([], [], [], [], [], true, 2));
        $this->seedBatch($store, 'run-1', 2, 's2', new ToolBatchStateDTO([], [], [], [], [], true, 2));

        $subscriber = $this->subscriber($store);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1',
            turnNo: 2,
            status: RunStatus::Completed->value,
            events: [new AfterTurnCommitEventSummary(99, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed'])],
            effectsCount: 0,
            runState: new RunState('run-1', RunStatus::Completed, turnNo: 2),
        ));

        $this->assertNull($store->load('run-1', 1, 's1'));
        $this->assertNull($store->load('run-1', 2, 's2'));
    }

    public function testCleanupInvokedAfterSuccessfulRunCommit(): void
    {
        $store = $this->createStore();
        $finalized = new ToolBatchStateDTO([], [], [], [], [], true, 2);
        $this->seedBatch($store, 'run-1', 1, 'step-1', $finalized);

        $activeRunContext = new TestActiveRunContext();
        $prev = RunState::queued('run-1');
        $activeRunContext->loadRecovered($prev);
        $commit = $this->createRunCommit($store, $activeRunContext);

        $next = new RunState(runId: 'run-1', status: RunStatus::Running, version: $prev->version + 1, turnNo: 1, lastSeq: 2, model: 'test-model');
        $events = [
            new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolBatchCommitted->value, [
                'count' => 1,
                'turn_no' => 1,
                'step_id' => 'step-1',
            ]),
        ];

        $commit->commit($prev, $next, $events, []);
        $this->assertNull($store->load('run-1', 1, 'step-1'));
    }

    public function testCanonicalToolResultDeletesFailedLaunchInputWithoutWaitingForSiblings(): void
    {
        $inputStore = $this->createMock(ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->once())->method('delete')->with('run-1', 'fork-call');
        $inputStore->expects($this->never())->method('deleteAllForRun');
        $subscriber = $this->subscriber($this->createStore(), $inputStore);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1', turnNo: 1, status: RunStatus::Running->value,
            events: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_result' => ['tool_call_id' => 'fork-call', 'result' => ['tool_name' => 'fork']]])],
            effectsCount: 0, runState: new RunState('run-1', RunStatus::Running, turnNo: 1),
        ));
    }

    public function testOrdinaryResultsDoNotResolveOrDeleteLaunchInput(): void
    {
        $store = $this->createMock(ToolBatchStoreInterface::class);
        $store->expects($this->never())->method('findUnresolvedToolCallIds');
        $store->expects($this->never())->method('hasUnresolvedExecution');
        $input = $this->createMock(ToolLaunchInputStoreInterface::class);
        $input->expects($this->never())->method('delete');
        $events = [];
        foreach (['read', 'bash', 'echo'] as $i => $name) {
            $events[] = new AfterTurnCommitEventSummary($i + 1, RunEventTypeEnum::ToolExecutionEnd->value,
                ['tool_result' => ['tool_call_id' => $name, 'result' => ['tool_name' => $name]]],
            );
        }
        $this->subscriber($store, $input)->handleAfterTurnCommit($this->context($events));
    }

    public function testDuplicateTerminalEventsResolveLaunchOwnersOnceAndDeleteBatchWithoutPreflight(): void
    {
        $store = $this->createMock(ToolBatchStoreInterface::class);
        $store->expects($this->once())->method('findUnresolvedToolCallIds')->with('run-1', ['failed-fork', 'pending-child'])->willReturn(['pending-child']);
        $store->expects($this->never())->method('hasUnresolvedExecution');
        $store->expects($this->never())->method('loadSchedule');
        $store->expects($this->once())->method('delete')->with('run-1', 1, 'tools');
        $input = $this->createMock(ToolLaunchInputStoreInterface::class);
        $input->expects($this->once())->method('delete')->with('run-1', 'failed-fork');
        $events = [];
        foreach (['failed-fork' => 'fork', 'pending-child' => 'subagent'] as $id => $name) {
            $events[] = new AfterTurnCommitEventSummary(1, RunEventTypeEnum::ToolExecutionEnd->value,
                ['tool_result' => ['tool_call_id' => $id, 'result' => ['tool_name' => $name]]],
            );
        }
        $events[] = $events[0];
        $events[] = new AfterTurnCommitEventSummary(3, RunEventTypeEnum::ToolBatchCommitted->value, ['turn_no' => 1, 'step_id' => 'tools']);
        $this->subscriber($store, $input)->handleAfterTurnCommit($this->context($events));
    }

    public function testExplicitAndTerminalDeletionRetainUnsettledBatches(): void
    {
        $store = $this->createStore();
        foreach (['unfinished', 'human', 'queued', 'inflight'] as $step) {
            $call = new ExecuteToolCall('run-1', 1, $step, 1, $step, $step, 'fork', [], 0);
            $this->seedBatch($store, 'run-1', 1, $step, new ToolBatchStateDTO(
                [$step => 0], [$step => $call], 'queued' === $step ? [$step] : [],
                'inflight' === $step ? [$step => true] : [], [], 'unfinished' !== $step, 1,
                'human' === $step ? [$step => 'question'] : [],
            ));
        }
        $input = $this->createMock(ToolLaunchInputStoreInterface::class);
        $input->expects($this->never())->method('deleteAllForRun');
        $subscriber = $this->subscriber($store, $input);
        foreach (['unfinished', 'human', 'queued', 'inflight'] as $step) {
            $subscriber->handleAfterTurnCommit($this->context([
                new AfterTurnCommitEventSummary(1, RunEventTypeEnum::ToolBatchCommitted->value, ['turn_no' => 1, 'step_id' => $step]),
            ]));
            $this->assertNotNull($store->loadSchedule('run-1', 1, $step));
        }
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'run-1', turnNo: 1, status: RunStatus::Cancelled->value,
            events: [new AfterTurnCommitEventSummary(2, RunEventTypeEnum::AgentEnd->value, [])], effectsCount: 0,
            runState: new RunState('run-1', RunStatus::Cancelled, turnNo: 1),
        ));
        $this->assertEqualsCanonicalizing(['unfinished', 'human', 'queued', 'inflight'], $store->findUnresolvedToolCallIds('run-1', ['unfinished', 'human', 'queued', 'inflight', 'absent']));
        $this->assertSame([], $store->findUnresolvedToolCallIds('other-run', ['human']));
        foreach (['unfinished', 'human', 'queued', 'inflight'] as $step) {
            $this->assertNotNull($store->loadSchedule('run-1', 1, $step));
        }
    }

    public function testFailedCanonicalCommitDoesNotDeleteLaunchInput(): void
    {
        $inputStore = $this->createMock(ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->never())->method('delete');
        $inputStore->expects($this->never())->method('deleteAllForRun');
        $eventStore = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $eventStore->method('appendTransition')->willThrowException(new \RuntimeException('append failed'));
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $eventStore,
            logger: new TestLogger(),
            hookDispatcher: new HookDispatcher([$this->subscriber($this->createStore(), $inputStore)]),
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher())),
        );
        $this->expectExceptionMessage('append failed');
        $commit->commit($previous, new RunState('run-1', RunStatus::Running, version: 1, turnNo: 1, model: 'test-model'), [new RunEvent('run-1', 1, 1, RunEventTypeEnum::ToolExecutionEnd->value, ['tool_result' => ['tool_call_id' => 'fork-call', 'result' => ['tool_name' => 'fork']]])]);
    }

    /** @param list<AfterTurnCommitEventSummary> $events */
    private function context(array $events): AfterTurnCommitHookContext
    {
        return new AfterTurnCommitHookContext('run-1', 1, RunStatus::Running->value, $events, 0, new RunState('run-1', RunStatus::Running, turnNo: 1));
    }

    private function seedBatch(ToolBatchStoreInterface $store, string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch): void
    {
        $store->prepareChanges([new \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO(
            $runId,
            $turnNo,
            $stepId,
            array_values($batch->calls),
            $batch->expectedOrder,
            array_values($batch->pendingQueue),
            $batch->inFlight,
            $batch->maxParallelism,
        )], new VerifiedTransitionDTO(\Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
            ['run_id' => $runId, 'actions' => []],
        ))();
        if ($batch->finalized || [] !== $batch->results || [] !== $batch->awaitingHumanInput || [] !== $batch->pendingQueue || [] !== $batch->inFlight) {
            $store->prepareChanges([new \Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO(
                $runId,
                $turnNo,
                $stepId,
                array_values($batch->pendingQueue),
                $batch->inFlight,
                $batch->awaitingHumanInput,
                $batch->finalized,
            )], new VerifiedTransitionDTO(\Symfony\Component\Uid\Uuid::v7()->toRfc4122(),
                ['run_id' => $runId, 'actions' => [new \Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO(
                    $runId,
                    $turnNo,
                    $stepId,
                    array_values($batch->pendingQueue),
                    $batch->inFlight,
                    $batch->awaitingHumanInput,
                    $batch->finalized,
                )]],
            ))();
        }
    }

    private function createStore(): ToolBatchStoreInterface
    {
        return new TestToolBatchStore();
    }

    private function subscriber(ToolBatchStoreInterface $store, ?ToolLaunchInputStoreInterface $inputStore = null): ToolBatchSnapshotCleanupHookSubscriber
    {
        return new ToolBatchSnapshotCleanupHookSubscriber(
            $store, new TestLogger(), $inputStore ?? $this->createStub(ToolLaunchInputStoreInterface::class),
            $this->createStub(ToolPayloadStoreInterface::class),
            new RunLockManager(new LockFactory(new InMemoryStore())),
            $this->createStub(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class),
        );
    }

    private function createRunCommit(ToolBatchStoreInterface $store, TestActiveRunContext $activeRunContext): RunCommit
    {
        $hookDispatcher = new HookDispatcher([
            $this->subscriber($store),
        ]);
        $eventStore = new CleanupHookSubscriberNoOpEventStore();

        return new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: new TestLogger(),
            hookDispatcher: $hookDispatcher,
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger(), events: new \Symfony\Component\EventDispatcher\EventDispatcher())),
        );
    }
}

final class CleanupHookSubscriberNoOpEventStore implements PreparedTransitionEventStoreInterface
{
    /** @var array<string, VerifiedTransitionDTO> */
    private array $pending = [];

    public function appendTransition(array $events, array $work): array
    {
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }
        $this->assertTransitionReady($runId);
        $this->pending[$runId] = new VerifiedTransitionDTO(
            hash('sha256', serialize([$work, $events])),
            $work,
            array_map(static fn (RunEvent $event): int => $event->seq, $events),
        );

        return $events;
    }

    public function assertTransitionReady(string $runId): void
    {
    }

    public function verifiedPendingTransition(string $runId): ?VerifiedTransitionDTO
    {
        return $this->pending[$runId] ?? null;
    }

    public function verifiedPendingBatch(string $runId, VerifiedTransitionDTO $verified): array
    {
        $pending = $this->verifiedPendingTransition($runId);
        if (null === $pending || $pending->identity !== $verified->identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }

        return [];
    }

    public function finalizeVerifiedTransition(string $runId, VerifiedTransitionDTO $verified): void
    {
        if (($this->pending[$runId]->identity ?? null) !== $verified->identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }
        unset($this->pending[$runId]);
    }

    public function assertVerifiedTransition(string $runId, VerifiedTransitionDTO $verified): void
    {
        if (($this->pending[$runId]->identity ?? null) !== $verified->identity) {
            throw new \RuntimeException('Fixture transition identity changed.');
        }
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        $events = $this->allFor($runId);

        return $events[0] ?? null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return [];
    }

    public function reverseFor(string $runId): iterable
    {
        return [];
    }

    public function allFor(string $runId): array
    {
        return [];
    }
}
