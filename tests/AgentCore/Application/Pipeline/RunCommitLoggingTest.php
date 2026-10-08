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
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger())),
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
            finalizer: TestTransitionFinalizerFactory::create(new RecordingEventStore(), new StepDispatcher(new TestMessageBus(), new TestMessageBus(), new TestLogger())),
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
            finalizer: TestTransitionFinalizerFactory::create($store, new StepDispatcher($bus, $bus, new TestLogger())),
        );

        try {
            $commit->commit($previous, $previous->with(['status' => RunStatus::Running]), [], [new \stdClass()]);
            $this->fail('Pending coordination must block even an event-free commit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('coordination pending', $exception->getMessage());
        }
        $this->assertSame($previous, $active->requireLoaded('run-1'));
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
