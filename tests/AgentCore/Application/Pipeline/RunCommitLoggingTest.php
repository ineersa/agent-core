<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use PHPUnit\Framework\TestCase;

/** Regression: commits log one canonical-event summary, not one line per event. */
final class RunCommitLoggingTest extends TestCase
{
    public function testCommitLogsSummaryOnlyAfterRememberingPersistedState(): void
    {
        $logger = new TestLogger();
        $activeRunContext = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $activeRunContext->loadRecovered($previous);
        $eventStore = new RecordingEventStore();

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            logger: $logger,
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus())),
        );

        $next = new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 0,
            model: 'test-model',
        );
        $events = [
            new RunEvent('run-1', 0, 1, 'user.message', ['text' => 'hi']),
            new RunEvent('run-1', 0, 1, 'assistant.message', ['text' => 'ok']),
        ];

        $commit->commit($previous, $next, $events);

        $this->assertSame(1, $eventStore->appendManyCalls);
        $this->assertCount(2, $eventStore->appended);
        $this->assertSame(2, $activeRunContext->requireLoaded('run-1')->lastSeq);
        $this->assertSame($next->version + 1, $activeRunContext->requireLoaded('run-1')->version);

        $messages = array_column($logger->records, 'message');
        $this->assertContains('persistence.events_committed', $messages);
        $this->assertNotContains('event_store.appended', $messages);
    }

    /** @return iterable<string, array{string, RunStatus, bool}> */
    public static function completedBatchEvents(): iterable
    {
        yield 'normal completion append failure' => ['tool_batch_committed', RunStatus::Running, false];
        yield 'cancellation append failure' => ['agent_end', RunStatus::Cancelled, false];
        yield 'normal completion publication failure' => ['tool_batch_committed', RunStatus::Running, true];
        yield 'cancellation publication failure' => ['agent_end', RunStatus::Cancelled, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('completedBatchEvents')]
    public function testCollectorRetainsFinalizedBatchUntilSuccessfulCommit(string $eventType, RunStatus $status, bool $failPublication): void
    {
        $collector = new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector();
        $call = new \Ineersa\AgentCore\Domain\Message\ExecuteToolCall(
            runId: 'run-1', turnNo: 1, stepId: 'tools', attempt: 1,
            idempotencyKey: 'call-key', toolCallId: 'read-call', toolName: 'read', args: [], orderIndex: 0,
        );
        $weakCall = \WeakReference::create($call);
        $collector->registerExpectedBatch('run-1', 1, 'tools', [$call]);
        unset($call);
        $result = \Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder::success('run-1')
            ->withTurnNo(1)->withStepId('tools')->withToolCallId('read-call')->build();
        $this->assertTrue(\Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($collector, $result)->complete);
        $this->assertNotNull($weakCall->get(), 'Finalizing collection precedes canonical commit and must not release the request.');
        $active = new FailingBatchPublicationContext();
        $previous = new RunState(runId: 'run-1', status: RunStatus::Running, turnNo: 1);
        $next = $previous->with(['status' => $status]);
        $active->loadRecovered($previous);
        $active->failRemember = $failPublication;
        $events = [new RunEvent('run-1', 0, 1, $eventType, ['turn_no' => 1, 'step_id' => 'tools'])];
        $store = new RecordingEventStore();
        $store->failAppend = !$failPublication;
        $cleanupStore = $this->createMock(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        $cleanupStore->expects('tool_batch_committed' === $eventType ? $this->once() : $this->never())
            ->method('delete')->willThrowException(new \RuntimeException('file deletion failed'));
        $cleanupStore->expects('agent_end' === $eventType ? $this->once() : $this->never())
            ->method('deleteAllForRun')->willThrowException(new \RuntimeException('file deletion failed'));
        $hook = new \Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber($cleanupStore, new TestLogger(), $this->createStub(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class));
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $store,
            logger: new TestLogger(),
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            hookDispatcher: new \Ineersa\AgentCore\Application\Handler\HookDispatcher([$hook]),
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher(new TestMessageBus(), new TestMessageBus())),
        );
        try {
            $commit->commit($previous, $next, $events);
            $this->fail('Commit failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failPublication ? 'publication failed' : 'append failed', $exception->getMessage());
        }
        $this->assertSame($result, $collector->getStoredResult('run-1', 1, 'tools', 'read-call'));
        $this->assertNotNull($weakCall->get());
        $this->assertSame($previous, $active->requireLoaded('run-1'));
        $store->failAppend = false;
        $active->failRemember = false;
        $commit->commit($previous, $next, $events);
        $this->assertNull($weakCall->get(), 'Successful commit releases requests even when durable file deletion fails.');
        $this->assertNull($collector->getStoredResult('run-1', 1, 'tools', 'read-call'));
        $this->assertSame($status, $active->requireLoaded('run-1')->status);
    }

    public function testNoEventCommitStillRemembersHandlerStateWithoutDiagnosticBump(): void
    {
        $activeRunContext = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $activeRunContext->loadRecovered($previous);
        $next = $previous->with(['status' => RunStatus::Running, 'version' => $previous->version + 1]);

        (new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: new RecordingEventStore(),
            logger: new TestLogger(),
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create(new RecordingEventStore(), new StepDispatcher(new TestMessageBus(), new TestMessageBus())),
        ))->commit($previous, $next, []);

        $this->assertSame($next, $activeRunContext->requireLoaded('run-1'));
    }

    public function testNoEventCommitCannotBypassPendingTransition(): void
    {
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('assertTransitionReady')->with('run-1')
            ->willThrowException(new \RuntimeException('coordination pending'));
        $store->expects($this->never())->method('appendTransition');
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $store,
            logger: new TestLogger(),
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher($bus, $bus)),
        );

        try {
            $commit->commit($previous, $previous->with(['status' => RunStatus::Running]), [], [new \stdClass()]);
            $this->fail('Pending coordination must block even an event-free commit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('coordination pending', $exception->getMessage());
        }
        $this->assertSame($previous, $active->requireLoaded('run-1'));
    }

    public function testDispositionFailureRetainsPendingTransition(): void
    {
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $event = new RunEvent('run-1', 1, 0, 'run_started', []);
        $descriptor = new \Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO(
            new \Ineersa\AgentCore\Domain\Message\DurableExecutionResult('run-1', 1, 'tools', 1, 'key', 'operation', 'claim', 'hash', 4, \Ineersa\AgentCore\Domain\Message\ToolCallResult::class),
            'Consumed',
        );
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('assertTransitionReady');
        $store->expects($this->once())->method('appendTransition')->willReturn([$event]);
        $store->expects($this->never())->method('finalizeVerifiedTransition');
        $verified = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO('transition', 0, ['run_id' => 'run-1', 'execution_disposition' => $descriptor]);
        $store->method('verifiedPendingTransition')->willReturn($verified);
        $operations = $this->createMock(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $operations->expects($this->once())->method('validateDisposition')->with($descriptor, $verified);
        $operations->expects($this->once())->method('applyDisposition')->with($descriptor, $verified)
            ->willThrowException(new \RuntimeException('disposition persistence failed'));
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $store,
            logger: new TestLogger(),
            executionOperations: $operations,
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher(new TestMessageBus(), new TestMessageBus()), operations: $operations),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disposition persistence failed');
        $commit->commit($previous, $previous, [$event], dispatchAfterTurnHooks: false, executionDisposition: $descriptor);
    }

    public function testDispositionPersistsBeforeTransitionFinalization(): void
    {
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $event = new RunEvent('run-1', 1, 0, 'run_started', []);
        $descriptor = new \Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO(
            new \Ineersa\AgentCore\Domain\Message\DurableExecutionResult('run-1', 1, 'tools', 1, 'key', 'operation', 'claim', 'hash', 4, \Ineersa\AgentCore\Domain\Message\ToolCallResult::class),
            'Consumed',
        );
        $disposed = false;
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('assertTransitionReady');
        $store->expects($this->once())->method('appendTransition')->willReturn([$event]);
        $store->expects($this->once())->method('finalizeVerifiedTransition')->willReturnCallback(function () use (&$disposed): void {
            $this->assertTrue($disposed, 'Required result disposition must be durable before deleting the transition manifest.');
        });
        $verified = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO('transition', 0, ['run_id' => 'run-1', 'execution_disposition' => $descriptor]);
        $store->method('verifiedPendingTransition')->willReturn($verified);
        $operations = $this->createMock(\Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface::class);
        $operations->expects($this->once())->method('validateDisposition')->with($descriptor, $verified);
        $operations->expects($this->once())->method('applyDisposition')->with($descriptor, $verified)->willReturnCallback(static function () use (&$disposed): void {
            $disposed = true;
        });
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $store,
            logger: new TestLogger(),
            executionOperations: $operations,
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher(new TestMessageBus(), new TestMessageBus()), operations: $operations),
        );
        $commit->commit($previous, $previous, [$event], dispatchAfterTurnHooks: false, executionDisposition: $descriptor);
    }

    public function testEffectDispatchFailurePropagatesWithoutFinalization(): void
    {
        $active = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $active->loadRecovered($previous);
        $event = new RunEvent('run-1', 1, 0, 'run_started', []);
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('assertTransitionReady');
        $store->expects($this->once())->method('appendTransition')->willReturn([$event]);
        $store->expects($this->never())->method('finalizeVerifiedTransition');
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('broker unavailable'));
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $store,
            logger: new TestLogger(),
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher($bus, $bus)),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('broker unavailable');
        $commit->commit($previous, $previous, [$event], [new \stdClass()]);
    }
}

final class FailingBatchPublicationContext implements \Ineersa\AgentCore\Contract\ActiveRunContextInterface
{
    public bool $failRemember = false;
    private readonly TestActiveRunContext $inner;

    public function __construct()
    {
        $this->inner = new TestActiveRunContext();
    }

    public function createNew(string $runId): RunState
    {
        $state = RunState::queued($runId);
        $this->loadRecovered($state);

        return $state;
    }

    public function loadRecovered(RunState $state): void
    {
        $this->inner->loadRecovered($state);
    }

    public function requireLoaded(string $runId): RunState
    {
        return $this->inner->requireLoaded($runId);
    }

    public function replaceCurrent(RunState $state): void
    {
        if ($this->failRemember) {
            throw new \RuntimeException('publication failed');
        }
        $this->inner->replaceCurrent($state);
    }

    public function release(string $runId): void
    {
        $this->inner->release($runId);
    }
}

final class RecordingEventStore implements PreparedTransitionEventStoreInterface
{
    public bool $failAppend = false;

    public int $appendManyCalls = 0;

    /** @var list<RunEvent> */
    public array $appended = [];

    /** @var array<string, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO> */
    private array $pending = [];

    public function appendTransition(array $events, array $work): array
    {
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }
        $this->assertTransitionReady($runId);

        ++$this->appendManyCalls;
        $out = [];
        foreach ($events as $event) {
            if ($this->failAppend) {
                throw new \RuntimeException('append failed');
            }
            $persisted = new RunEvent($event->runId, \count($this->appended) + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
            $this->appended[] = $persisted;
            $out[] = $persisted;
        }
        $this->pending[$runId] = new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(
            hash('sha256', serialize([$work, $out])),
            0,
            $work,
            array_map(static fn (RunEvent $event): int => $event->seq, $out),
        );

        return $out;
    }

    public function assertTransitionReady(string $runId): void
    {
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        return $this->pending[$runId] ?? null;
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        $pending = $this->verifiedPendingTransition($runId);
        if (null === $pending || $pending->identity !== $identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }

        return [];
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        if (($this->pending[$runId]->identity ?? null) !== $identity) {
            throw new \RuntimeException('Fixture transition identity mismatch.');
        }
        unset($this->pending[$runId]);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        return $this->allFor($runId)[0] ?? null;
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        foreach ($this->allFor($runId) as $event) {
            if ($event->seq >= $startSeq && $event->seq <= $endSeq) {
                yield $event;
            }
        }
    }

    public function reverseFor(string $runId): iterable
    {
        return array_reverse($this->allFor($runId));
    }

    public function allFor(string $runId): array
    {
        return array_values(array_filter($this->appended, static fn (RunEvent $event): bool => $event->runId === $runId));
    }
}
