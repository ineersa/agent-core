<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Regression: generic deferred tool completion persists ExecuteToolCall correlation,
 * skips immediate ToolCallResult, and completes later through the canonical bus path.
 */
#[Group('db')]
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

        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());

        $message = $this->executeMessage(toolCallId: 'call-immediate');

        $result = $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertSame('run-deferred-1', $result->runId());
        $this->assertSame(3, $result->turnNo());
        $this->assertSame('turn-3-tools-1', $result->stepId());
        $this->assertSame(2, $result->attempt());
        // Durable outcomes retain the authorized invocation identity.
        $this->assertSame($message->idempotencyKey(), $result->idempotencyKey());
        $this->assertNotSame('', $result->idempotencyKey());
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

        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());

        $message = $this->executeMessage(toolCallId: 'call-deferred');

        $this->assertNull($worker($message));

        $this->assertSame(1, $toolExecutor->calls);

        $pending = $repo->findByRunAndToolCall('run-deferred-1', 'call-deferred');
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

        $repo = new InMemoryDeferredToolCompletionRepository();
        $worker = new ExecuteToolCallWorker($toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());
        $message = $this->executeMessage(toolCallId: 'call-retry');

        $this->assertNull($worker($message));
        $this->assertNull($worker($message));

        $this->assertSame(1, $toolExecutor->calls);
    }

    public function testRegisterPendingReturnsCanonicalCorrelationForDuplicateRunAndToolCall(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();

        $first = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-a', toolCallId: 'call-dup-reg'));
        $second = $repo->registerPending($this->sampleCorrelation(deferredId: 'def-b', toolCallId: 'call-dup-reg'));

        $this->assertSame($first->deferredId, $second->deferredId);
        $this->assertSame('def-a', $second->deferredId);
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
        /** @var list<DeferredToolCompletionRegisteredEvent> $events */
        $events = [];
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addListener(
            DeferredToolCompletionRegisteredEvent::class,
            static function (DeferredToolCompletionRegisteredEvent $event) use (&$events): void {
                $events[] = $event;
            },
        );

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
        $worker = new ExecuteToolCallWorker($toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(), eventDispatcher: $dispatcher);

        $worker($this->executeMessage(toolCallId: 'call-exact-id'));

        $pending = $repo->findByRunAndToolCall('run-deferred-1', 'call-exact-id');
        $this->assertNotNull($pending);
        $this->assertSame('lifecycle-exact-1', $pending->deferredId);
        $this->assertCount(1, $events);
        $this->assertSame('lifecycle-exact-1', $events[0]->correlation->deferredId);
    }

    public function testPendingRedeliveryDispatchesRegisteredEventWithoutSecondExecution(): void
    {
        $count = 0;
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $dispatcher->addListener(
            DeferredToolCompletionRegisteredEvent::class,
            static function () use (&$count): void {
                ++$count;
            },
        );

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
        $worker = new ExecuteToolCallWorker($toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(), eventDispatcher: $dispatcher);
        $message = $this->executeMessage(toolCallId: 'call-redelivery-event');
        $worker($message);
        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertSame(2, $count);
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

    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        return $this->inner->findByRunAndToolCall($runId, $toolCallId);
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
