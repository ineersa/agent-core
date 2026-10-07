<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateDuplicateSequenceReplayException;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\WorkerFailedEventSubscriber;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

final class WorkerFailedEventSubscriberTest extends IsolatedKernelTestCase
{
    private const string RUN_ID = 'test-run-123';
    private const string RECEIVER_NAME = 'run_control';

    #[Test]
    public function skipsWhenRetryWillHappen(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('requireLoaded');
        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendTransition');

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
        $event = new WorkerMessageFailedEvent(new Envelope($this->createStartRun()), self::RECEIVER_NAME, new \RuntimeException('test'));
        $event->setForRetry();

        $subscriber->onWorkerMessageFailed($event);
    }

    #[Test]
    public function skipsNonAgentBusMessage(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->never())->method('requireLoaded');
        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendTransition');

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
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
        $activeContext->expects($this->never())->method('requireLoaded');
        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendTransition');

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
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
            ->method('requireLoaded')
            ->with(self::RUN_ID)
            ->willReturn(new RunState(runId: self::RUN_ID, status: RunStatus::Failed, version: 5, model: 'test-model'));
        $activeContext->expects($this->never())->method('replaceCurrent');
        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendTransition');

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('test')));
    }

    #[Test]
    public function writesFailedStateAndEventForNewRun(): void
    {
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->expects($this->once())
            ->method('requireLoaded')
            ->with(self::RUN_ID)
            ->willReturn(RunState::queued(self::RUN_ID));
        $activeContext->expects($this->once())
            ->method('replaceCurrent')
            ->with($this->callback(static fn (RunState $state): bool => self::RUN_ID === $state->runId
                && RunStatus::Failed === $state->status
                && 1 === $state->version
                && 1 === $state->lastSeq));

        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('appendTransition')
            ->with($this->callback(static fn (array $events): bool => self::RUN_ID === $events[0]->runId
                && 'agent_end' === $events[0]->type
                && 'failed' === ($events[0]->payload['reason'] ?? '')
                && 0 === $events[0]->seq))
            ->willReturnCallback(static fn (array $events): array => [new RunEvent(
                $events[0]->runId,
                1,
                $events[0]->turnNo,
                $events[0]->type,
                $events[0]->payload,
            )]);

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
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
        $activeContext->expects($this->once())->method('requireLoaded')->with(self::RUN_ID)->willReturn($existingState);
        $activeContext->expects($this->once())
            ->method('replaceCurrent')
            ->with($this->callback(static fn (RunState $state): bool => self::RUN_ID === $state->runId
                && RunStatus::Failed === $state->status
                && 4 === $state->version
                && 6 === $state->lastSeq
                && str_contains($state->errorMessage ?? '', 'transition failed')));

        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('appendTransition')
            ->willReturnCallback(static fn (array $events): array => [new RunEvent(
                $events[0]->runId,
                6,
                $events[0]->turnNo,
                $events[0]->type,
                $events[0]->payload,
            )]);

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
        $subscriber->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('transition failed')));
    }

    #[Test]
    public function logsProjectionFailureAfterCanonicalTerminalEventWithoutThrowing(): void
    {
        $currentState = new RunState(runId: self::RUN_ID, status: RunStatus::Running, version: 3, turnNo: 1, lastSeq: 4, model: 'test-model');
        $activeContext = $this->createMock(ActiveRunContextInterface::class);
        $activeContext->method('requireLoaded')->willReturn($currentState);
        $activeContext->expects($this->once())
            ->method('replaceCurrent')
            ->willThrowException(new \RuntimeException('projection unavailable'));

        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('appendTransition')
            ->willReturn([new RunEvent(self::RUN_ID, 5, 1, 'agent_end', ['reason' => 'failed'])]);
        $logger = new TestLogger();

        $subscriber = $this->subscriber($activeContext, $eventStore, $logger);
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
        $activeContext->expects($this->never())->method('requireLoaded');
        $activeContext->expects($this->never())->method('replaceCurrent');
        $eventStore = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $eventStore->expects($this->never())->method('appendTransition');

        $subscriber = $this->subscriber($activeContext, $eventStore, new NullLogger());
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

    public function testConfiguredFailureCommitsTerminalStateAndCleansRuntimeFilesOnce(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('failure fixture');
        $active = self::getContainer()->get(ActiveRunContextInterface::class);
        $active->loadRecovered(new RunState($runId, RunStatus::Running, version: 3, turnNo: 2, model: 'test-model'));
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        $batches->save($runId, 1, 'older', new ToolBatchStateDTO([], [], [], [], [], false, 2));
        $batches->save($runId, 2, 'current', new ToolBatchStateDTO([], [], [], [], [], false, 2));
        $inputs = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = $inputs->publish('fork', $runId, 2, 'current', 'call', 'test-model', '',
            [new AgentMessage('user', [['type' => 'text', 'text' => 'context']])]);
        $this->assertNotNull($batches->load($runId, 1, 'older'));
        $this->assertSame($runId, $inputs->read($reference)->producingRunId);
        $subscriber = self::getContainer()->get(WorkerFailedEventSubscriber::class);
        $event = new WorkerMessageFailedEvent(new Envelope($this->createStartRun($runId)), 'run_control', new \RuntimeException('handler failed'));
        $subscriber->onWorkerMessageFailed($event);
        $subscriber->onWorkerMessageFailed($event);
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $events = iterator_to_array($store->rangeFor($runId, 1, \PHP_INT_MAX));
        $this->assertCount(1, $events);
        $this->assertSame('agent_end', $events[0]->type);
        $this->assertSame('failed', $events[0]->payload['reason']);
        $this->assertSame('handler failed', $events[0]->payload['error']);
        $state = $active->requireLoaded($runId);
        $this->assertSame(RunStatus::Failed, $state->status);
        $this->assertSame(4, $state->version);
        $this->assertSame($events[0]->seq, $state->lastSeq);
        $operational = self::getContainer()->get(RunOperationalStatusReaderInterface::class)->findOperationalStatus($runId);
        $this->assertSame(RunStatus::Failed, $operational?->status);
        $this->assertSame($state->lastSeq, $operational?->lastEventSequence);
        $this->assertNull($batches->load($runId, 1, 'older'));
        $this->assertNull($batches->load($runId, 2, 'current'));
        $this->expectException(\RuntimeException::class);
        $inputs->read($reference);
    }

    public function testRealRunLockCoversLoadAppendAndPublicationAndIsReleased(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('failure-lock');
        try {
            $manager = new RunLockManager(new LockFactory(new FlockStore($dir)));
            $other = (new LockFactory(new FlockStore($dir)))->createLock('agent_loop.run.'.self::RUN_ID);
            $order = [];
            $context = $this->createMock(ActiveRunContextInterface::class);
            $context->expects($this->once())->method('requireLoaded')->willReturnCallback(function () use ($other, &$order): RunState {
                $this->assertFalse($other->acquire());
                $order[] = 'load';

                return RunState::queued(self::RUN_ID);
            });
            $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
            $store->expects($this->once())->method('appendTransition')->willReturnCallback(function (array $events) use ($other, &$order): array {
                $event = $events[0];
                $this->assertFalse($other->acquire());
                $order[] = 'append';

                return [new RunEvent($event->runId, 1, $event->turnNo, $event->type, $event->payload)];
            });
            $context->expects($this->once())->method('replaceCurrent')->willReturnCallback(function (RunState $state) use ($other, &$order): void {
                $this->assertFalse($other->acquire());
                $this->assertSame(1, $state->lastSeq);
                $order[] = 'publish';
            });
            $this->subscriber($context, $store, new NullLogger(), $manager)->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('failure')));
            $this->assertSame(['load', 'append', 'publish'], $order);
            $this->assertTrue($other->acquire());
            $other->release();
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testHeldRunLockFailsWithoutLoadOrAlternateAppend(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('failure-lock-held');
        $other = (new LockFactory(new FlockStore($dir)))->createLock('agent_loop.run.'.self::RUN_ID);
        try {
            $this->assertTrue($other->acquire());
            $context = $this->createMock(ActiveRunContextInterface::class);
            $context->expects($this->never())->method('requireLoaded');
            $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
            $store->expects($this->never())->method('appendTransition');
            $logger = new TestLogger();
            $manager = new RunLockManager(new LockFactory(new FlockStore($dir)), acquireTimeoutSeconds: 0.001);
            $this->subscriber($context, $store, $logger, $manager)->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('failure')));
            $this->assertSame('agent_loop.worker_failed_subscriber_error', $logger->records[array_key_last($logger->records)]['message']);
        } finally {
            $other->release();
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testAppendFailureDoesNotPublishStateOrRetryAppend(): void
    {
        $context = $this->createMock(ActiveRunContextInterface::class);
        $context->expects($this->once())->method('requireLoaded')->willReturn(RunState::queued(self::RUN_ID));
        $context->expects($this->never())->method('replaceCurrent');
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('appendTransition')->willThrowException(new \RuntimeException('append failed'));
        $logger = new TestLogger();
        $this->subscriber($context, $store, $logger)->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('failure')));
        $this->assertSame('agent_loop.worker_failed_subscriber_error', $logger->records[array_key_last($logger->records)]['message']);
    }

    public function testLoadFailureDoesNotAttemptTerminalAppend(): void
    {
        $context = $this->createMock(ActiveRunContextInterface::class);
        $context->expects($this->once())->method('requireLoaded')->willThrowException(new \RuntimeException('recovery unavailable'));
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->never())->method('appendTransition');
        $logger = new TestLogger();
        $this->subscriber($context, $store, $logger)->onWorkerMessageFailed($this->createFinalFailedEvent(new \RuntimeException('failure')));
        $this->assertSame('agent_loop.worker_failed_subscriber_error', $logger->records[array_key_last($logger->records)]['message']);
    }

    public function testCollectorReleasePrecedesCleanupAndCleanupFailureDoesNotRepeatTerminalization(): void
    {
        $active = new TestActiveRunContext();
        $active->loadRecovered(RunState::queued(self::RUN_ID));
        $collector = new ToolBatchCollector();
        $request = new ExecuteToolCall(self::RUN_ID, 1, 'step', 1, 'identity', 'call', 'read', [], 0);
        $reference = \WeakReference::create($request);
        $collector->registerExpectedBatch(self::RUN_ID, 1, 'step', [$request]);
        unset($request);
        $this->assertNotNull($reference->get());
        $hook = $this->createMock(HookSubscriberInterface::class);
        $hook->expects($this->once())->method('handleAfterTurnCommit')->willReturnCallback(function (AfterTurnCommitHookContext $context) use ($reference, $active): AfterTurnCommitHookContext {
            $this->assertNull($reference->get());
            $this->assertSame(RunStatus::Failed, $active->requireLoaded(self::RUN_ID)->status);
            $this->assertSame(1, $context->events[0]->seq);
            throw new \RuntimeException('cleanup unavailable');
        });
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('appendTransition')->willReturnCallback(static fn (array $events): array => [new RunEvent($events[0]->runId, 1, $events[0]->turnNo, $events[0]->type, $events[0]->payload)]);
        $logger = new TestLogger();
        $bus = new TestMessageBus();
        $commit = new RunCommit($active, $store, new StepDispatcher($bus, $bus), $logger, $collector, new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()), new HookDispatcher([$hook]));
        $subscriber = new WorkerFailedEventSubscriber($active, $commit, new RunLockManager(new LockFactory(new InMemoryStore())), $logger);
        $event = $this->createFinalFailedEvent(new \RuntimeException('handler failed'));
        $subscriber->onWorkerMessageFailed($event);
        $subscriber->onWorkerMessageFailed($event);
        $this->assertSame(1, $active->requireLoaded(self::RUN_ID)->lastSeq);
        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'After-turn commit hook failed (best-effort)' === $record['message']));
        $this->assertCount(1, $warnings);
        $this->assertSame('cleanup unavailable', $warnings[0]['context']['exception']->getMessage());
    }

    public function testPermanentFailureRunsCleanupWithoutAutomaticModelWork(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('failed auto compaction');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($run, 1, 'llm_step_completed', ['usage' => ['input_tokens' => 12000]]));
        $active = new TestActiveRunContext();
        $state = new RunState($run, RunStatus::Running, turnNo: 1, lastSeq: 1, model: 'test-model', messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'fresh content']])]);
        $active->loadRecovered($state);
        $bus = new TestMessageBus();
        $compaction = $this->createStub(\Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface::class);
        $compaction->method('prepare')->willReturn(\Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult::ready(messagesToSummarize: $state->messages, retainedTailMessages: [], tokenEstimateBefore: 12000, messagesCompacted: 1, messagesRetained: 0, firstRetainedIndex: 1, priorSummaryPresent: false));
        $auto = new \Ineersa\CodingAgent\Compaction\AutoCompactionHookSubscriber(new \Ineersa\CodingAgent\Compaction\ProviderContextUsageResolver($store), new \Ineersa\CodingAgent\Config\CompactionConfig(autoEnabled: true, compactAfterTokens: 11000, keepRecentTokens: 10), $this->createStub(\Ineersa\AgentCore\Contract\Model\RunModelResolverInterface::class), $compaction, \Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader::topLevel($run));
        $cleanup = $this->createMock(HookSubscriberInterface::class);
        $cleanup->expects($this->once())->method('handleAfterTurnCommit')->willReturnCallback(function (AfterTurnCommitHookContext $context): AfterTurnCommitHookContext {
            $this->assertSame(RunStatus::Failed, $context->runState->status);

            return $context;
        });
        $commit = new RunCommit($active, $store, new StepDispatcher($bus, $bus), new NullLogger(), new ToolBatchCollector(), new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()), new HookDispatcher([$auto, $cleanup]));
        $subscriber = new WorkerFailedEventSubscriber($active, $commit, $container->get(RunLockManager::class), new NullLogger());
        $subscriber->onWorkerMessageFailed(new WorkerMessageFailedEvent(new Envelope(new StartRun($run, 0, 'failed-start', 1, 'failed-start', new StartRunPayload('', [], new RunMetadata(model: 'test-model')))), 'run_control', new \RuntimeException('permanent failure')));
        $this->assertSame(RunStatus::Failed, $active->requireLoaded($run)->status);
        $this->assertSame([], $bus->messages);
        // Positive control uses the same usage and partition with successful completion.
        $completed = $state->with(['status' => RunStatus::Completed]);
        $actions = $auto->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($completed, [RunEvent::forAppend($run, 1, 'agent_end', ['reason' => 'completed'])], 0), $completed->lastSeq);
        $this->assertCount(1, $actions);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO::class, $actions[0]);
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\CompactRun::class, $actions[0]->message);
    }

    private function subscriber(ActiveRunContextInterface $context, PreparedTransitionEventStoreInterface $store, LoggerInterface $logger, ?RunLockManager $lockManager = null): WorkerFailedEventSubscriber
    {
        $bus = new TestMessageBus();

        return new WorkerFailedEventSubscriber($context,
            new RunCommit($context, $store, new StepDispatcher($bus, $bus), $logger, new ToolBatchCollector(), new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore())),
            $lockManager ?? new RunLockManager(new LockFactory(new InMemoryStore())), $logger);
    }

    private function createFinalFailedEvent(\Throwable $exception): WorkerMessageFailedEvent
    {
        return new WorkerMessageFailedEvent(new Envelope($this->createStartRun()), self::RECEIVER_NAME, $exception);
    }

    private function createStartRun(string $runId = self::RUN_ID): StartRun
    {
        return new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik-test-123',
            payload: new StartRunPayload(systemPrompt: 'test prompt', metadata: new RunMetadata(model: 'test-model')),
        );
    }
}
