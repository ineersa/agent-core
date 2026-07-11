<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use PHPUnit\Framework\TestCase;

/**
 * Regression: commit must not emit one INFO line per canonical event.
 */
final class RunCommitLoggingTest extends TestCase
{
    public function testCommitLogsSummaryOnlyNotPerEventAppends(): void
    {
        $logger = new TestLogger();
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued('run-1'), 0);

        $eventStore = new RecordingEventStore();

        $replayService = new SessionHotPromptReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
            promptStateReplayService: new PromptStateReplayService(),
            replayEventPreparer: new ReplayEventPreparer(),
        );

        $stepDispatcher = new StepDispatcher(new TestMessageBus());

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: $replayService,
            stepDispatcher: $stepDispatcher,
            logger: $logger,
        );

        $previous = $runStore->get('run-1');
        $this->assertNotNull($previous);

        $next = new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 2,
        );

        $events = [
            new RunEvent('run-1', 1, 1, 'user.message', ['text' => 'hi']),
            new RunEvent('run-1', 2, 1, 'assistant.message', ['text' => 'ok']),
        ];

        $this->assertTrue($commit->commit($previous, $next, $events, []));
        $this->assertSame(1, $eventStore->appendManyCalls);
        $this->assertCount(2, $eventStore->appended);

        $messages = array_column($logger->records, 'message');
        $this->assertContains('persistence.events_committed', $messages);
        $this->assertNotContains('event_store.appended', $messages);
    }

    public function testLastSeqBumpUsesReloadedStateWhenPostPersistCasFails(): void
    {
        $logger = new TestLogger();
        $inner = new InMemoryRunStore();
        $inner->compareAndSwap(RunState::queued('run-1'), 0);
        $inner->compareAndSwap(new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
        ), 0);

        $runStore = new FailsSecondCompareAndSwapRunStore($inner);
        $eventStore = new RecordingEventStore();

        $replayService = new SessionHotPromptReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
            promptStateReplayService: new PromptStateReplayService(),
            replayEventPreparer: new ReplayEventPreparer(),
        );

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: $replayService,
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: $logger,
        );

        $previous = $inner->get('run-1');
        $this->assertNotNull($previous);

        $next = new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 0,
        );

        $events = [
            new RunEvent('run-1', 99, 1, 'user.message', ['text' => 'hi']),
            new RunEvent('run-1', 99, 1, 'assistant.message', ['text' => 'ok']),
        ];

        $this->assertTrue($commit->commit($previous, $next, $events, []));

        $messages = array_column($logger->records, 'message');
        $this->assertContains('persistence.last_seq_cas_conflict', $messages);
        $this->assertCount(2, $eventStore->appended);

        $stored = $inner->get('run-1');
        $this->assertNotNull($stored);
        // First CAS (running v2) succeeded; lastSeq bump CAS failed so lastSeq may remain 0.
        $this->assertSame(2, $stored->version);
        $this->assertSame(0, $stored->lastSeq);
    }

    public function testCommitMetricsUseStoreTruthWhenPostPersistLastSeqCasFails(): void
    {
        $metrics = new RunMetrics();
        $logger = new TestLogger();
        $inner = new InMemoryRunStore();
        $inner->compareAndSwap(RunState::queued('run-1'), 0);
        $inner->compareAndSwap(new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
        ), 0);

        $runStore = new ReloadsCompletedAfterSecondCasFailRunStore($inner);
        $eventStore = new RecordingEventStore();

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            hotPromptStateRebuilder: new SessionHotPromptReplayService(
                eventStore: $eventStore,
                promptStateStore: new InMemoryPromptStateStore(),
                promptStateReplayService: new PromptStateReplayService(),
                replayEventPreparer: new ReplayEventPreparer(),
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            logger: $logger,
            metrics: $metrics,
        );

        $previous = $inner->get('run-1');
        $this->assertNotNull($previous);

        $next = new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: $previous->version + 1,
            turnNo: 1,
            lastSeq: 0,
        );

        $events = [
            new RunEvent('run-1', 99, 1, 'user.message', ['text' => 'hi']),
        ];

        $this->assertTrue($commit->commit($previous, $next, $events, []));

        $snapshot = $metrics->snapshot();
        $this->assertSame(0, $snapshot['active_runs_by_status'][RunStatus::Running->value] ?? 0);
        $this->assertSame(1, $snapshot['active_runs_by_status'][RunStatus::Completed->value] ?? 0,
            'Metrics must use reloaded store truth (Completed), not the intended post-persist Running bump state.');
    }
}

final class ReloadsCompletedAfterSecondCasFailRunStore implements RunStoreInterface
{
    private int $casCalls = 0;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function get(string $runId): ?RunState
    {
        if ($this->casCalls >= 2) {
            return new RunState(
                runId: $runId,
                status: RunStatus::Completed,
                version: 3,
                turnNo: 0,
                lastSeq: 0,
            );
        }

        return $this->inner->get($runId);
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        ++$this->casCalls;
        if (2 === $this->casCalls) {
            return false;
        }

        return $this->inner->compareAndSwap($state, $expectedVersion);
    }

    public function findRunningStaleBefore(\DateTimeImmutable $threshold): array
    {
        return $this->inner->findRunningStaleBefore($threshold);
    }
}

final class FailsSecondCompareAndSwapRunStore implements RunStoreInterface
{
    private int $casCalls = 0;

    public function __construct(private readonly InMemoryRunStore $inner)
    {
    }

    public function get(string $runId): ?RunState
    {
        return $this->inner->get($runId);
    }

    public function compareAndSwap(RunState $state, int $expectedVersion): bool
    {
        ++$this->casCalls;
        if (2 === $this->casCalls) {
            return false;
        }

        return $this->inner->compareAndSwap($state, $expectedVersion);
    }

    public function findRunningStaleBefore(\DateTimeImmutable $threshold): array
    {
        return $this->inner->findRunningStaleBefore($threshold);
    }
}

final class RecordingEventStore implements SequencedEventStoreInterface
{
    public int $appendManyCalls = 0;

    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): void
    {
        $this->appended[] = $event;
    }

    public function appendWithNextSeq(RunEvent $event): RunEvent
    {
        $seq = \count($this->appended) + 1;
        $persisted = new RunEvent($event->runId, $seq, $event->turnNo, $event->type, $event->payload, $event->createdAt);
        $this->appended[] = $persisted;

        return $persisted;
    }

    public function appendManyWithNextSeq(array $events): array
    {
        ++$this->appendManyCalls;
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->appendWithNextSeq($event);
        }

        return $out;
    }

    public function appendMany(array $events): void
    {
        ++$this->appendManyCalls;
        foreach ($events as $event) {
            $this->appended[] = $event;
        }
    }

    public function allFor(string $runId): array
    {
        return array_values(array_filter(
            $this->appended,
            static fn (RunEvent $event): bool => $event->runId === $runId,
        ));
    }
}
