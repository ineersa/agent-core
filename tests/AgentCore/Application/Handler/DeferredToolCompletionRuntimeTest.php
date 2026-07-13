<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Regression: generic deferred tool completion persists ExecuteToolCall correlation,
 * skips immediate ToolCallResult, and completes later through the canonical bus path.
 */
#[Group('db')]
final class DeferredRegisteredEventCollector implements \Symfony\Component\EventDispatcher\EventDispatcherInterface
{
    /** @var list<DeferredToolCompletionRegisteredEvent> */
    public array $events = [];
    public int $count = 0;

    public function dispatch(object $event, ?string $eventName = null): object
    {
        if ($event instanceof DeferredToolCompletionRegisteredEvent) {
            $this->events[] = $event;
            ++$this->count;
        }

        return $event;
    }

    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
    }

    public function addSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
    }

    public function removeListener(string $eventName, callable|array $listener): void
    {
    }

    public function removeSubscriber(\Symfony\Component\EventDispatcher\EventSubscriberInterface $subscriber): void
    {
    }

    public function getListeners(?string $eventName = null): array
    {
        return [];
    }

    public function getListenerPriority(string $eventName, callable|array $listener): ?int
    {
        return null;
    }

    public function hasListeners(?string $eventName = null): bool
    {
        return false;
    }
}

final class DeferredToolCompletionRuntimeTest extends IsolatedKernelTestCase
{
    public function testImmediateToolStillDispatchesCanonicalToolCallResult(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'ok']],
                    details: ['echo' => $toolCall->arguments],
                    isError: false,
                );
            }
        };

        $commandBus = new TestMessageBus();
        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus, $repo);

        $message = $this->executeMessage(toolCallId: 'call-immediate');

        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertCount(1, $commandBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);
        /** @var ToolCallResult $result */
        $result = $commandBus->messages[0];
        $this->assertSame('run-deferred-1', $result->runId());
        $this->assertSame(3, $result->turnNo());
        $this->assertSame('turn-3-tools-1', $result->stepId());
        $this->assertSame(2, $result->attempt());
        $this->assertSame('tool-idemp-immediate', $result->idempotencyKey());
        $this->assertSame('call-immediate', $result->toolCallId);
        $this->assertSame(1, $result->orderIndex);
        $this->assertSame('parallel', $result->result['mode']);
        $this->assertSame(['query' => 'x'], $result->result['arguments']);
    }

    public function testDeferredToolPersistsCorrelationAndDispatchesNoImmediateResult(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome('def-outcome-1')],
                    isError: false,
                );
            }
        };

        $commandBus = new TestMessageBus();
        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus, $repo);

        $message = $this->executeMessage(toolCallId: 'call-deferred');

        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertCount(0, $commandBus->messages);

        $pending = $repo->findPendingByRunAndToolCall('run-deferred-1', 'call-deferred');
        $this->assertNotNull($pending);
        $this->assertSame('run-deferred-1', $pending->runId);
        $this->assertSame(3, $pending->turnNo);
        $this->assertSame('turn-3-tools-1', $pending->stepId);
        $this->assertSame(2, $pending->attempt);
        $this->assertSame('tool-idemp-immediate', $pending->idempotencyKey);
        $this->assertSame('call-deferred', $pending->toolCallId);
        $this->assertSame(1, $pending->orderIndex);
        $this->assertSame('parallel', $pending->mode);
        $this->assertSame(['query' => 'x'], $pending->arguments);
        $this->assertSame(120, $pending->timeoutSeconds);
    }

    public function testRetriedExecuteToolCallReusesPendingRecordWithoutSecondExecution(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome('def-outcome-1')],
                    isError: false,
                );
            }
        };

        $commandBus = new TestMessageBus();
        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus, $repo);
        $message = $this->executeMessage(toolCallId: 'call-retry');

        $worker($message);
        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertCount(0, $commandBus->messages);
    }

    public function testRegisterPendingReturnsCanonicalCorrelationForDuplicateRunAndToolCall(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();

        $first = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-a', toolCallId: 'call-dup-reg'));
        $second = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-b', toolCallId: 'call-dup-reg'));

        $this->assertSame($first->deferredId, $second->deferredId);
        $this->assertSame('def-a', $second->deferredId);
    }

    public function testCompleteDeferredToolCallDispatchesCanonicalResultFromStoredCorrelation(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome('def-outcome-1')],
                    isError: false,
                );
            }
        };

        $commandBus = new TestMessageBus();
        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus, $repo);
        $message = $this->executeMessage(toolCallId: 'call-complete');
        $worker($message);

        $pending = $repo->findPendingByRunAndToolCall('run-deferred-1', 'call-complete');
        $this->assertNotNull($pending);

        $completionBus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler($repo, $completionBus, new TestLogger());

        $handler(new CompleteDeferredToolCall(
            deferredId: $pending->deferredId,
            content: [['type' => 'text', 'text' => 'final']],
            details: ['done' => true],
            isError: false,
            error: null,
        ));

        $this->assertCount(1, $completionBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $completionBus->messages[0]);
        /** @var ToolCallResult $result */
        $result = $completionBus->messages[0];
        $this->assertSame('run-deferred-1', $result->runId());
        $this->assertSame(3, $result->turnNo());
        $this->assertSame('turn-3-tools-1', $result->stepId());
        $this->assertSame(2, $result->attempt());
        $this->assertSame('tool-idemp-immediate', $result->idempotencyKey());
        $this->assertSame('call-complete', $result->toolCallId);
        $this->assertSame('web_search', $result->result['tool_name']);
        $this->assertSame(1, $result->orderIndex);
        $this->assertSame('parallel', $result->result['mode']);
        $this->assertSame(['query' => 'x'], $result->result['arguments']);
        $this->assertSame('final', $result->result['content'][0]['text']);
    }

    public function testDispatchFailureLeavesPendingAndRetryDispatches(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();
        $correlation = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-dispatch-fail', toolCallId: 'call-fail-dispatch'));

        $bus = new DeferredCompletionFailingOnceMessageBus();
        $handler = new CompleteDeferredToolCallHandler($repo, $bus, new TestLogger());

        $complete = new CompleteDeferredToolCall(
            deferredId: $correlation->deferredId,
            content: [['type' => 'text', 'text' => 'late']],
            isError: false,
        );

        try {
            $handler($complete);
            $this->fail('Expected dispatch failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Failed to dispatch', $exception->getMessage());
        }

        $this->assertSame('pending', $repo->status($correlation->deferredId));
        $this->assertCount(1, $bus->messages);

        $handler($complete);

        $this->assertSame('completed', $repo->status($correlation->deferredId));
        $this->assertCount(2, $bus->messages);
    }

    public function testMarkCompletedFailureAfterDispatchAllowsRedispatchWithIdempotentPipelineHandling(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();
        $correlation = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-mark-fail', toolCallId: 'call-mark-fail'));

        $innerBus = new TestMessageBus();
        $failingRepo = new MarkCompletedFailsOnceRepository($repo);
        $handler = new CompleteDeferredToolCallHandler($failingRepo, $innerBus, new TestLogger());

        $complete = new CompleteDeferredToolCall(
            deferredId: $correlation->deferredId,
            content: [['type' => 'text', 'text' => 'payload']],
            isError: false,
        );

        try {
            $handler($complete);
            $this->fail('Expected markCompleted failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('mark failed', $exception->getMessage());
        }

        $this->assertSame('pending', $repo->status($correlation->deferredId));
        $this->assertCount(1, $innerBus->messages);

        $handler($complete);
        $this->assertSame('completed', $repo->status($correlation->deferredId));
        $this->assertCount(2, $innerBus->messages);

        $toolCallResult = $innerBus->messages[0];
        $this->assertInstanceOf(ToolCallResult::class, $toolCallResult);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(
            RunStateBuilder::running('run-deferred-1')
                ->withVersion(1)
                ->withTurnNo(3)
                ->withLastSeq(0)
                ->withPendingToolCalls(['call-mark-fail' => false])
                ->withActiveStepId('turn-3-tools-1')
                ->build(),
            0,
        );

        $eventStore = new InMemoryEventStore();
        $replayService = new SessionHotPromptReplayService(
            eventStore: $eventStore,
            promptStateStore: new InMemoryPromptStateStore(),
            promptStateReplayService: new PromptStateReplayService(),
            replayEventPreparer: new ReplayEventPreparer(),
        );

        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch('run-deferred-1', 3, 'turn-3-tools-1', [
            new ExecuteToolCall(
                runId: 'run-deferred-1',
                turnNo: 3,
                stepId: 'turn-3-tools-1',
                attempt: 2,
                idempotencyKey: 'tool-idemp-immediate',
                toolCallId: 'call-mark-fail',
                toolName: 'web_search',
                args: ['query' => 'x'],
                orderIndex: 1,
                mode: 'parallel',
            ),
        ]);

        $processor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: new RunCommit(
                runStore: $runStore,
                eventStore: $eventStore,
                commandStore: new InMemoryCommandStore(),
                hotPromptStateRebuilder: $replayService,
                stepDispatcher: new StepDispatcher(new TestMessageBus()),
                logger: new NullLogger(),
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            handlers: [
                new ToolCallResultHandler(
                    toolBatchCollector: $collector,
                    eventFactory: new EventFactory(),
                    toolCallExtractor: new ToolCallExtractor(),
                    messageNormalizer: new AgentMessageNormalizer(),
                ),
            ],
            logger: new NullLogger(),
        );

        $processor->process('result.tool', $toolCallResult);
        $stateAfterFirst = $runStore->get('run-deferred-1');
        $this->assertNotNull($stateAfterFirst);
        $this->assertSame([], $stateAfterFirst->pendingToolCalls);

        $processor->process('result.tool', $toolCallResult);
        $stateAfterSecond = $runStore->get('run-deferred-1');
        $this->assertNotNull($stateAfterSecond);
        $this->assertSame([], $stateAfterSecond->pendingToolCalls);
        $this->assertCount(1, $stateAfterSecond->messages);
    }

    public function testDuplicateCompletionAfterCompletedIsNoOp(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();
        $correlation = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-dup-1', toolCallId: 'call-dup'));

        $bus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler($repo, $bus, new TestLogger());

        $complete = new CompleteDeferredToolCall(
            deferredId: $correlation->deferredId,
            content: [['type' => 'text', 'text' => 'once']],
            isError: false,
        );

        $handler($complete);
        $handler($complete);

        $this->assertCount(1, $bus->messages);
        $this->assertSame('completed', $repo->status($correlation->deferredId));
    }

    public function testUnknownDeferredCorrelationFailsDiagnostically(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();
        $bus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler($repo, $bus, new TestLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unknown deferred tool completion id');

        $handler(new CompleteDeferredToolCall(
            deferredId: 'missing-id',
            content: [['type' => 'text', 'text' => 'nope']],
            isError: false,
        ));
    }

    public function testDoctrineRepositoryPersistsPendingCorrelation(): void
    {
        /** @var DeferredToolCompletionRepository $repo */
        $repo = self::getContainer()->get(DeferredToolCompletionRepository::class);

        $correlation = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440000',
            runId: 'run-db-1',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-db',
            toolCallId: 'call-db',
            toolName: 'read',
            arguments: ['path' => './a.txt'],
            orderIndex: 0,
            timeoutSeconds: 30,
        ));

        $loaded = $repo->findByDeferredId($correlation->deferredId);
        $this->assertNotNull($loaded);
        $this->assertSame('run-db-1', $loaded->runId);
        $this->assertSame('call-db', $loaded->toolCallId);
        $this->assertSame(30, $loaded->timeoutSeconds);
        $this->assertSame('pending', $repo->status($correlation->deferredId));

        $canonical = $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '660e8400-e29b-41d4-a716-446655440001',
            runId: 'run-db-1',
            turnNo: 9,
            stepId: 'other-step',
            attempt: 9,
            idempotencyKey: 'other-key',
            toolCallId: 'call-db',
            toolName: 'write',
            arguments: ['path' => './b.txt'],
            orderIndex: 2,
        ));

        $this->assertSame($correlation->deferredId, $canonical->deferredId);
        $this->assertSame('read', $canonical->toolName);
    }

    public function testDoctrineRegisterPendingRejectsConflictingDeferredIdForDifferentRunToolCall(): void
    {
        /** @var DeferredToolCompletionRepository $repo */
        $repo = self::getContainer()->get(DeferredToolCompletionRepository::class);

        $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '770e8400-e29b-41d4-a716-446655440002',
            runId: 'run-db-conflict-a',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-a',
            toolCallId: 'call-a',
            toolName: 'read',
            arguments: [],
            orderIndex: 0,
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot register run "run-db-conflict-b" tool call "call-b"');

        $repo->registerPending(new DeferredToolCompletionCorrelation(
            deferredId: '770e8400-e29b-41d4-a716-446655440002',
            runId: 'run-db-conflict-b',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-b',
            toolCallId: 'call-b',
            toolName: 'write',
            arguments: [],
            orderIndex: 0,
        ));
    }

    public function testDeferredToolRegistersExactOutcomeDeferredIdAndDispatchesRegisteredEvent(): void
    {
        $dispatcher = new DeferredRegisteredEventCollector();

        $toolExecutor = new class implements ToolExecutorInterface {
            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome('lifecycle-exact-1')],
                    isError: false,
                );
            }
        };

        $commandBus = new TestMessageBus();
        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus, $repo, eventDispatcher: $dispatcher);

        $worker($this->executeMessage(toolCallId: 'call-exact-id'));

        $pending = $repo->findPendingByRunAndToolCall('run-deferred-1', 'call-exact-id');
        $this->assertNotNull($pending);
        $this->assertSame('lifecycle-exact-1', $pending->deferredId);
        $this->assertCount(1, $dispatcher->events);
        $this->assertSame('lifecycle-exact-1', $dispatcher->events[0]->correlation->deferredId);
    }

    public function testPendingRedeliveryDispatchesRegisteredEventWithoutSecondExecution(): void
    {
        $dispatcher = new DeferredRegisteredEventCollector();

        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome('lifecycle-redelivery-1')],
                    isError: false,
                );
            }
        };

        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, new TestMessageBus(), $repo, eventDispatcher: $dispatcher);
        $message = $this->executeMessage(toolCallId: 'call-redelivery-event');
        $worker($message);
        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertSame(2, $dispatcher->count);
    }

    private function executeMessage(string $toolCallId): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: 'run-deferred-1',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 2,
            idempotencyKey: 'tool-idemp-immediate',
            toolCallId: $toolCallId,
            toolName: 'web_search',
            args: ['query' => 'x'],
            orderIndex: 1,
            toolIdempotencyKey: 'tool-invocation-1',
            mode: 'parallel',
            timeoutSeconds: 120,
        );
    }

    private function sampleCorrelation(string $deferredId, string $toolCallId): DeferredToolCompletionCorrelation
    {
        return new DeferredToolCompletionCorrelation(
            deferredId: $deferredId,
            runId: 'run-deferred-1',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 2,
            idempotencyKey: 'tool-idemp-immediate',
            toolCallId: $toolCallId,
            toolName: 'web_search',
            arguments: ['query' => 'x'],
            orderIndex: 1,
            mode: 'parallel',
            timeoutSeconds: 120,
        );
    }
}

final class DeferredCompletionFailingOnceMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    private int $failuresRemaining = 1;

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        if ($this->failuresRemaining > 0) {
            --$this->failuresRemaining;

            throw new MessageDecodingFailedException('simulated transport dispatch failure');
        }

        return new Envelope($message, [new HandledStamp(null, 'test')]);
    }
}

final class MarkCompletedFailsOnceRepository implements DeferredToolCompletionRepositoryInterface
{
    public function __construct(
        private readonly DeferredToolCompletionRepositoryInterface $inner,
        private int $failuresRemaining = 1,
    ) {
    }

    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        return $this->inner->registerPending($correlation);
    }

    public function findPendingByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        return $this->inner->findPendingByRunAndToolCall($runId, $toolCallId);
    }

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation
    {
        return $this->inner->findByDeferredId($deferredId);
    }

    public function status(string $deferredId): ?string
    {
        return $this->inner->status($deferredId);
    }

    public function markCompleted(string $deferredId): void
    {
        if ($this->failuresRemaining > 0) {
            --$this->failuresRemaining;

            throw new \RuntimeException('mark failed');
        }

        $this->inner->markCompleted($deferredId);
    }
}
