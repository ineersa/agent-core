<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

/** Regression: commits log one canonical-event summary, not one line per event. */
final class RunCommitLoggingTest extends TestCase
{
    public function testCommitLogsSummaryOnlyAfterRememberingPersistedState(): void
    {
        $logger = new TestLogger();
        $activeRunContext = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $activeRunContext->remember($previous);
        $eventStore = new RecordingEventStore();

        $commit = new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: $eventStore,
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: $logger,
            toolBatchCollector: new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(),
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
        $this->assertSame(2, $activeRunContext->stateFor('run-1')->lastSeq);
        $this->assertSame($next->version + 1, $activeRunContext->stateFor('run-1')->version);

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
        $this->assertTrue($collector->collect($result)->complete);
        $this->assertNotNull($weakCall->get(), 'Finalizing collection precedes canonical commit and must not release the request.');
        $active = new FailingBatchPublicationContext();
        $previous = new RunState(runId: 'run-1', status: RunStatus::Running, turnNo: 1);
        $next = $previous->with(['status' => $status]);
        $active->remember($previous);
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
            activeRunContext: $active, eventStore: $store,
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new TestLogger(), toolBatchCollector: $collector,
            hookDispatcher: new \Ineersa\AgentCore\Application\Handler\HookDispatcher([$hook]),
        );
        try {
            $commit->commit($previous, $next, $events);
            $this->fail('Commit failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failPublication ? 'publication failed' : 'append failed', $exception->getMessage());
        }
        $this->assertSame($result, $collector->getStoredResult('run-1', 1, 'tools', 'read-call'));
        $this->assertNotNull($weakCall->get());
        $this->assertSame($previous, $active->stateFor('run-1'));
        $store->failAppend = false;
        $active->failRemember = false;
        $commit->commit($previous, $next, $events);
        $this->assertNull($weakCall->get(), 'Successful commit releases requests even when durable file deletion fails.');
        $this->assertNull($collector->getStoredResult('run-1', 1, 'tools', 'read-call'));
        $this->assertSame($status, $active->stateFor('run-1')->status);
    }

    public function testNoEventCommitStillRemembersHandlerStateWithoutDiagnosticBump(): void
    {
        $activeRunContext = new TestActiveRunContext();
        $previous = RunState::queued('run-1');
        $activeRunContext->remember($previous);
        $next = $previous->with(['status' => RunStatus::Running, 'version' => $previous->version + 1]);

        (new RunCommit(
            activeRunContext: $activeRunContext,
            eventStore: new RecordingEventStore(),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            logger: new TestLogger(),
            toolBatchCollector: new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(),
        ))->commit($previous, $next, []);

        $this->assertSame($next, $activeRunContext->stateFor('run-1'));
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

    public function stateFor(string $runId): RunState
    {
        return $this->inner->stateFor($runId);
    }

    public function remember(RunState $state): void
    {
        if ($this->failRemember) {
            throw new \RuntimeException('publication failed');
        }
        $this->inner->remember($state);
    }

    public function invalidate(string $runId): void
    {
        $this->inner->invalidate($runId);
    }

    public function clear(): void
    {
        $this->inner->clear();
    }
}

final class RecordingEventStore implements EventStoreInterface
{
    public bool $failAppend = false;

    public int $appendManyCalls = 0;

    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): RunEvent
    {
        if ($this->failAppend) {
            throw new \RuntimeException('append failed');
        }
        $persisted = new RunEvent($event->runId, \count($this->appended) + 1, $event->turnNo, $event->type, $event->payload, $event->createdAt);
        $this->appended[] = $persisted;

        return $persisted;
    }

    public function appendMany(array $events): array
    {
        ++$this->appendManyCalls;

        return array_map($this->append(...), $events);
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
