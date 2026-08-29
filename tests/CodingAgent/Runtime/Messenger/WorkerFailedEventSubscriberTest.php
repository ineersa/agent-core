<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Messenger\WorkerFailedEventSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WorkerFailedEventSubscriberTest extends TestCase
{
    private const string RUN_ID = 'test-run-123';
    private const string RECEIVER_NAME = 'run_control';

    #[Test]
    public function skipsWhenRetryWillHappen(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('stateFor');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $event = new WorkerMessageFailedEvent(new Envelope($this->createStartRun()), self::RECEIVER_NAME, new \RuntimeException('test'));
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);
    }

    #[Test]
    public function skipsNonAgentBusMessage(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('stateFor');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope(new \stdClass()),
            self::RECEIVER_NAME,
            new \RuntimeException('test'),
        ));
    }

    #[Test]
    public function skipsNonRunControlTransport(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('stateFor');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(
            new Envelope($this->createStartRun()),
            'llm',
            new \RuntimeException('test'),
        ));
    }

    #[Test]
    public function skipsWhenRunAlreadyTerminal(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->once())
            ->method('stateFor')
            ->with(self::RUN_ID)
            ->willReturn(new RunState(runId: self::RUN_ID, status: RunStatus::Failed, version: 5, model: 'test-model'));
        $activeContext->expects($this->never())->method('remember');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('test')));
    }

    #[Test]
    public function writesFailedStateAndEventForNewRun(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->once())
            ->method('stateFor')
            ->with(self::RUN_ID)
            ->willReturn(RunState::queued(self::RUN_ID));
        $activeContext->expects($this->once())
            ->method('remember')
            ->with($this->callback(static fn (RunState $state): bool => self::RUN_ID === $state->runId
                && RunStatus::Failed === $state->status
                && 1 === $state->version
                && 1 === $state->lastSeq));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->with($this->callback(static fn (RunEvent $event): bool => self::RUN_ID === $event->runId
                && 'agent_end' === $event->type
                && 'failed' === ($event->payload['reason'] ?? '')
                && 0 === $event->seq))
            ->willReturnCallback(static fn (RunEvent $event): RunEvent => new RunEvent(
                $event->runId,
                1,
                $event->turnNo,
                $event->type,
                $event->payload,
            ));

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('Database connection lost')));
    }

    #[Test]
    public function writesFailedStateAndEventForExistingRun(): void
    {
        $existingState = new RunState(
            runId: self::RUN_ID,
            status: RunStatus::Running,
            version: 3,
            turnNo: 2,
            lastSeq: 5,
            model: 'test-model',
        );

        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->once())->method('stateFor')->with(self::RUN_ID)->willReturn($existingState);
        $activeContext->expects($this->once())
            ->method('remember')
            ->with($this->callback(static fn (RunState $state): bool => self::RUN_ID === $state->runId
                && RunStatus::Failed === $state->status
                && 4 === $state->version
                && 6 === $state->lastSeq
                && str_contains($state->errorMessage ?? '', 'transition failed')));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturnCallback(static fn (RunEvent $event): RunEvent => new RunEvent(
                $event->runId,
                6,
                $event->turnNo,
                $event->type,
                $event->payload,
            ));

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('transition failed')));
    }

    #[Test]
    public function logsProjectionFailureAfterCanonicalTerminalEventWithoutThrowing(): void
    {
        $currentState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, turnNo: 1, lastSeq: 4, model: 'test-model');
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->method('stateFor')->willReturn($currentState);
        $activeContext->expects($this->once())
            ->method('remember')
            ->willThrowException(new \RuntimeException('projection unavailable'));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturn(new RunEvent(self::RUN_ID, 5, 1, 'agent_end', ['reason' => 'failed']));
        $logger = new TestLogger();

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, $logger);
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('test')));

        $errors = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'agent_loop.worker_failed_subscriber_error' === $record['message'],
        ));
        $this->assertCount(1, $errors);
        $this->assertSame('projection unavailable', $errors[0]['context']['exception']->getMessage());
    }

    #[Test]
    public function skipsTypedDuplicateReplayCorruptionWithoutLoadingState(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('stateFor');
        $activeContext->expects($this->never())->method('remember');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(
            new RunStateDuplicateSequenceReplayException('duplicate'),
        ));
    }

    #[Test]
    public function getSubscribedEventsReturnsWorkerMessageFailedEvent(): void
    {
        $events = WorkerFailedEventSubscriber::getSubscribedEvents();

        $this->assertSame('onWorkerMessageFailed', $events[WorkerMessageFailedEvent::class]);
    }

    private function createFinalFailedEvent(\Throwable $exception): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(new Envelope($this->createStartRun()), self::RECEIVER_NAME, $exception);
    }

    private function createStartRun(): StartRun
    {
        return new StartRun(
            runId: self::RUN_ID,
            turnNo: 0,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik-test-123',
            payload: new StartRunPayload(systemPrompt: 'test prompt', metadata: new RunMetadata(model: 'test-model')),
        );
    }
}
