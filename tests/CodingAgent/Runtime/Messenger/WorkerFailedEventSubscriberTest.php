<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\Messenger\WorkerFailedEventSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

class WorkerFailedEventSubscriberTest extends TestCase
{
    private const string RUN_ID = 'test-run-123';
    private const string RECEIVER_NAME = 'run_control';

    #[Test]
    public function skipsWhenRetryWillHappen(): void
    {
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('compareAndSwap');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = new WorkerMessageFailedEvent($envelope, self::RECEIVER_NAME, new \RuntimeException('test'));
        // Simulate that SendFailedMessageForRetryListener has determined retry should happen.
        $event->setForRetry();  // willRetry = true

        $subscriber->onWorkerMessageFailed($event);

        $this->assertTrue(true);
    }

    #[Test]
    public function skipsNonAgentBusMessage(): void
    {
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('compareAndSwap');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        // A plain object, not an AbstractAgentBusMessage
        $envelope = new Envelope(new \stdClass());
        $event = new WorkerMessageFailedEvent($envelope, self::RECEIVER_NAME, new \RuntimeException('test'));

        $subscriber->onWorkerMessageFailed($event);

        $this->assertTrue(true);
    }

    #[Test]
    public function skipsNonRunControlTransport(): void
    {
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->never())->method('compareAndSwap');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = new WorkerMessageFailedEvent($envelope, 'llm', new \RuntimeException('test'));

        $subscriber->onWorkerMessageFailed($event);

        $this->assertTrue(true);
    }

    #[Test]
    public function skipsWhenRunAlreadyTerminal(): void
    {
        $runState = new RunState(runId: self::RUN_ID, status: RunStatus::Failed, version: 5, model: 'test-model');

        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturn($runState);
        $runStore->expects($this->never())->method('compareAndSwap');
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');
        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = $this->createFinalFailedEvent($envelope, new \RuntimeException('test'));

        $subscriber->onWorkerMessageFailed($event);

        $this->assertTrue(true);
    }

    #[Test]
    public function writesFailedStateAndEventForNewRun(): void
    {
        // StartRun failed before any state was committed (get returns null)
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturn(null);
        $runStore->expects($this->once())
            ->method('compareAndSwap')
            ->with($this->callback(static function (RunState $state): bool {
                return self::RUN_ID === $state->runId
                    && RunStatus::Failed === $state->status
                    && 1 === $state->version
                    && 1 === $state->lastSeq;
            }), 0)
            ->willReturn(true);

        $capturedEvent = null;
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->with($this->callback(static function (RunEvent $event): bool {
                return self::RUN_ID === $event->runId
                    && 'agent_end' === $event->type
                    && 'failed' === ($event->payload['reason'] ?? '')
                    && 0 === $event->seq;
            }))
            ->willReturnCallback(static function (RunEvent $event) use (&$capturedEvent): RunEvent {
                $capturedEvent = new RunEvent($event->runId, 1, $event->turnNo, $event->type, $event->payload);

                return $capturedEvent;
            });

        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $exception = new \RuntimeException('Database connection lost');
        $event = $this->createFinalFailedEvent($envelope, $exception);

        $subscriber->onWorkerMessageFailed($event);

        $this->assertNotNull($capturedEvent);
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
            messages: [],
            model: 'test-model');

        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturn($existingState);

        $committedState = null;
        $runStore->expects($this->once())
            ->method('compareAndSwap')
            ->with($this->callback(static function (RunState $state) use (&$committedState): bool {
                $committedState = $state;

                return self::RUN_ID === $state->runId
                    && RunStatus::Failed === $state->status
                    && 4 === $state->version   // version 3 + 1
                    && 6 === $state->lastSeq;   // lastSeq 5 + 1
            }), 3)
            ->willReturn(true);

        $capturedEvent = null;
        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturnCallback(static function (RunEvent $event) use (&$capturedEvent): RunEvent {
                $capturedEvent = new RunEvent($event->runId, 6, $event->turnNo, $event->type, $event->payload);

                return $capturedEvent;
            });

        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = $this->createFinalFailedEvent($envelope, new \RuntimeException('CAS conflict exhausted'));

        $subscriber->onWorkerMessageFailed($event);

        $this->assertNotNull($capturedEvent);
        $this->assertNotNull($committedState);
        $this->assertStringContainsString('CAS conflict exhausted', $committedState->errorMessage ?? '');
    }

    #[Test]
    public function handlesCasConflictAfterSequencedAppendWithoutThrowing(): void
    {
        $currentState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, turnNo: 1, lastSeq: 4, model: 'test-model');

        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturnOnConsecutiveCalls($currentState, $currentState);
        $runStore->expects($this->once())
            ->method('compareAndSwap')
            ->willReturn(false);

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturn(new RunEvent(self::RUN_ID, 5, 1, 'agent_end', ['reason' => 'failed']));

        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = $this->createFinalFailedEvent($envelope, new \RuntimeException('test'));

        $subscriber->onWorkerMessageFailed($event);
        $this->assertTrue(true);
    }

    #[Test]
    public function handlesCasConflictExceptionGracefully(): void
    {
        $currentState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, model: 'test-model');

        $runStore = $this->createStub(RunStoreInterface::class);
        $runStore->method('get')->willReturn($currentState);
        // CAS throws an exception
        $runStore->method('compareAndSwap')->willThrowException(new \RuntimeException('Lock acquisition failed'));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('append')
            ->willReturn(new RunEvent(self::RUN_ID, 5, 1, 'agent_end', ['reason' => 'failed']));

        $logger = new NullLogger();

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, $logger);

        $message = $this->createStartRun();
        $envelope = new Envelope($message);
        $event = $this->createFinalFailedEvent($envelope, new \RuntimeException('test'));

        // Should not throw (append-before-CAS: terminal event may persist even when CAS throws)
        $subscriber->onWorkerMessageFailed($event);
        $this->assertTrue(true);
    }

    #[Test]
    public function getSubscribedEventsReturnsWorkerMessageFailedEvent(): void
    {
        $events = WorkerFailedEventSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
        $this->assertSame('onWorkerMessageFailed', $events[WorkerMessageFailedEvent::class]);
    }

    #[Test]
    public function skipsOnlyTypedDuplicateReplayCorruption(): void
    {
        $currentState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, turnNo: 1, lastSeq: 4, model: 'test-model');

        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->method('get')->willReturn($currentState);

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->never())->method('append');

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, new NullLogger());
        $message = $this->createStartRun();

        $duplicate = new RunStateDuplicateSequenceReplayException('duplicate');

        $runStore->expects($this->never())->method('compareAndSwap');
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new Envelope($message), $duplicate));
    }

    /**
     * Create a WorkerMessageFailedEvent that represents a final (no-more-retries) failure.
     *
     * WorkerMessageFailedEvent starts with willRetry() = false by default
     * (the constructor initializes $willRetry to false). It becomes true
     * only when SendFailedMessageForRetryListener calls setForRetry().
     * A final failure is one where setForRetry() was never called.
     */
    private function createFinalFailedEvent(Envelope $envelope, \Throwable $exception): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent($envelope, self::RECEIVER_NAME, $exception);
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
