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
        ))->commit($previous, $next, []);

        $this->assertSame($next, $activeRunContext->stateFor('run-1'));
    }
}

final class RecordingEventStore implements EventStoreInterface
{
    public int $appendManyCalls = 0;

    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): RunEvent
    {
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
