<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
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

        $replayService = new ReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
        );

        $stepDispatcher = new StepDispatcher(new TestMessageBus());

        $commit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: new InMemoryCommandStore(),
            replayService: $replayService,
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
}

final class RecordingEventStore implements EventStoreInterface
{
    public int $appendManyCalls = 0;

    /** @var list<RunEvent> */
    public array $appended = [];

    public function append(RunEvent $event): void
    {
        $this->appended[] = $event;
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
