<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
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
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
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
        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());

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
        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());

        $message = $this->executeMessage(toolCallId: 'call-deferred');
        $repo->preparePending($this->sampleCorrelation(deferredId: 'def-outcome-1', toolCallId: 'call-deferred'));

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
        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());
        $message = $this->executeMessage(toolCallId: 'call-retry');
        $repo->preparePending($this->sampleCorrelation(deferredId: 'def-outcome-1', toolCallId: 'call-retry'));

        $this->assertNull($worker($message));
        // Owner readiness (launch success / host ready / communication accept)
        // is what prevents a second execution on redelivery.
        $repo->markReady('def-outcome-1');
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

        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('deferred persistence');
        $correlation = $this->registerDoctrineCorrelation(new DeferredToolCompletionCorrelation(
            deferredId: '550e8400-e29b-41d4-a716-446655440000',
            runId: $runId,
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
        $this->assertSame($runId, $loaded->runId);
        $this->assertSame('call-db', $loaded->toolCallId);
        $this->assertSame(30, $loaded->timeoutSeconds);
        $this->assertSame('pending', $repo->status($correlation->deferredId));

        $canonical = $this->registerDoctrineCorrelation(new DeferredToolCompletionCorrelation(
            deferredId: '660e8400-e29b-41d4-a716-446655440001',
            runId: $runId,
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
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $firstRun = $sessions->createSession('deferred conflict first');
        $secondRun = $sessions->createSession('deferred conflict second');
        $this->registerDoctrineCorrelation(new DeferredToolCompletionCorrelation(
            deferredId: '770e8400-e29b-41d4-a716-446655440002',
            runId: $firstRun,
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
        $this->expectExceptionMessage('cannot register run "'.$secondRun.'" tool call "call-b"');

        $this->registerDoctrineCorrelation(new DeferredToolCompletionCorrelation(
            deferredId: '770e8400-e29b-41d4-a716-446655440002',
            runId: $secondRun,
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
        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(), eventDispatcher: $dispatcher);
        $repo->preparePending($this->sampleCorrelation(deferredId: 'lifecycle-exact-1', toolCallId: 'call-exact-id'));

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
        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(), eventDispatcher: $dispatcher);
        $message = $this->executeMessage(toolCallId: 'call-redelivery-event');
        $repo->preparePending($this->sampleCorrelation(deferredId: 'lifecycle-redelivery-1', toolCallId: 'call-redelivery-event'));
        $worker($message);
        $repo->markReady('lifecycle-redelivery-1');
        $worker($message);

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertSame(2, $count);
    }

    public function testPreparedPendingWithoutReadyAtExecutesThroughWorkerAndPublishesResult(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'prepared-host-ran']],
                    details: ['path' => 'worker'],
                    isError: false,
                );
            }
        };

        $repo = new InMemoryDeferredToolCompletionRepository();
        $prepared = $repo->preparePending($this->sampleCorrelation(deferredId: 'prepared-1', toolCallId: 'call-prepared'));
        $this->assertNull($repo->findMetadataByDeferredId($prepared->deferredId)?->readyAt);

        $commandBus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($commandBus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());
        $result = $worker($this->executeMessage(toolCallId: 'call-prepared'));

        $this->assertSame(1, $toolExecutor->calls);
        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertSame('prepared-host-ran', $result->result['content'][0]['text'] ?? null);
        $this->assertCount(1, $commandBus->messages);
        $this->assertSame($result, $commandBus->messages[0]);
    }

    public function testReadyPendingAndCompletedSkipWorkerExecution(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public int $calls = 0;

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'should-not-run']],
                    details: null,
                    isError: false,
                );
            }
        };

        $repo = new InMemoryDeferredToolCompletionRepository();
        $ready = $repo->registerPending($this->sampleCorrelation(deferredId: 'ready-1', toolCallId: 'call-ready'));
        $this->assertNotNull($repo->findMetadataByDeferredId($ready->deferredId)?->readyAt);

        $worker = new ExecuteToolCallWorker(new TestMessageBus(), new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());
        $this->assertNull($worker($this->executeMessage(toolCallId: 'call-ready')));
        $this->assertSame(0, $toolExecutor->calls);

        $completed = $repo->preparePending($this->sampleCorrelation(deferredId: 'completed-1', toolCallId: 'call-completed'));
        $repo->markCompleted($completed->deferredId);
        $this->assertNull($worker($this->executeMessage(toolCallId: 'call-completed')));
        $this->assertSame(0, $toolExecutor->calls);
    }

    public function testFailureBeforeReadyPublishesFailedToolCallResult(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new \RuntimeException('host failed before ready');
            }
        };

        $repo = new InMemoryDeferredToolCompletionRepository();
        $repo->preparePending($this->sampleCorrelation(deferredId: 'fail-1', toolCallId: 'call-fail'));
        $commandBus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($commandBus, new \Ineersa\AgentCore\Tests\Support\TestLogger(), $toolExecutor, $repo, new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());
        $result = $worker($this->executeMessage(toolCallId: 'call-fail'));

        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('host failed before ready', (string) ($result->result['content'][0]['text'] ?? ''));
        $this->assertCount(1, $commandBus->messages);
        $this->assertNull($repo->findMetadataByDeferredId('fail-1')?->readyAt);
    }

    public function testDoctrinePreparePendingLeavesReadyAtNullAndRegisterPendingStampsReadyAt(): void
    {
        /** @var DeferredToolCompletionRepository $repo */
        $repo = self::getContainer()->get(DeferredToolCompletionRepository::class);
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('prepare vs register readiness');

        $prepared = new DeferredToolCompletionCorrelation(
            deferredId: '880e8400-e29b-41d4-a716-446655440010',
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'idemp-prepare',
            toolCallId: 'call-prepare',
            toolName: 'code_mode',
            arguments: ['script' => 'return 1;'],
            orderIndex: 0,
        );
        TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $prepared);
        $repo->preparePending($prepared);
        $this->assertNull($repo->findMetadataByDeferredId($prepared->deferredId)?->readyAt);

        $registered = new DeferredToolCompletionCorrelation(
            deferredId: '990e8400-e29b-41d4-a716-446655440011',
            runId: $runId,
            turnNo: 1,
            stepId: 'turn-1-tools-2',
            attempt: 1,
            idempotencyKey: 'idemp-register',
            toolCallId: 'call-register',
            toolName: 'notify_parent',
            arguments: ['text' => 'hi'],
            orderIndex: 0,
        );
        TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $registered);
        $repo->preparePending($registered);
        $this->assertNull($repo->findMetadataByDeferredId($registered->deferredId)?->readyAt);
        $repo->markReady($registered->deferredId);
        $this->assertNotNull($repo->findMetadataByDeferredId($registered->deferredId)?->readyAt);
    }

    public function testOwnerPreparedCodeModeWorkerExecutesRealHostAndReturnsOrdinaryResult(): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('owner prepared code_mode worker');
        $call = new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: 'code-mode-worker-step',
            attempt: 1,
            idempotencyKey: 'code-mode-worker-key',
            toolCallId: 'code-mode-worker-call',
            toolName: 'code_mode',
            args: ['script' => 'return 42;'],
            orderIndex: 0,
            mode: 'parallel',
            maxParallelism: 2,
            timeoutSeconds: 10,
        );
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(
            new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector($batches),
            $batches,
            $runId,
            1,
            'code-mode-worker-step',
            [$call],
        );
        $call = $batches->loadCall($runId, 1, 'code-mode-worker-step', 'code-mode-worker-call');
        $this->assertInstanceOf(ExecuteToolCall::class, $call);

        $deferredId = $container->get(\Ineersa\CodingAgent\Application\Pipeline\CodeModeLifecycleService::class)->prepare($call);
        $metadata = $container->get(DeferredToolCompletionRepository::class)->findMetadataByDeferredId($deferredId);
        $this->assertNotNull($metadata);
        $this->assertNull($metadata->readyAt, 'Owner preparation must leave readyAt null.');

        $host = $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge::class);
        $toolbox = new class($host) implements \Symfony\AI\Agent\Toolbox\ToolboxInterface {
            public function __construct(private readonly \Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge $host)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(\Symfony\AI\Platform\Result\ToolCall $toolCall): \Symfony\AI\Agent\Toolbox\ToolResult
            {
                $arguments = $toolCall->getArguments();
                $result = $this->host->execute(
                    (string) ($arguments['script'] ?? ''),
                    isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : \Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO::DEFAULT_TIMEOUT_SECONDS,
                    isset($arguments['memory_limit_mb']) ? (int) $arguments['memory_limit_mb'] : \Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO::DEFAULT_MEMORY_LIMIT_MB,
                    (bool) ($arguments['backgrounded'] ?? false),
                );

                return new \Symfony\AI\Agent\Toolbox\ToolResult($toolCall, [
                    'code_mode_value' => $result instanceof \Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult ? $result->result : $result,
                    'code_mode_diagnostics' => $result instanceof \Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult ? $result->diagnostics : [],
                ]);
            }
        };

        $executor = new \Ineersa\AgentCore\Application\Handler\ToolExecutor(
            defaultMode: 'parallel',
            maxParallelism: 2,
            resultStore: new ToolExecutionResultStore(),
            toolbox: $toolbox,
            contextAccessor: $container->get(\Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor::class),
            toolResultProcessors: [
                $container->get(\Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor::class),
                $container->get(\Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor::class),
            ],
        );
        $commandBus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker(
            $commandBus,
            new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            $executor,
            $container->get(DeferredToolCompletionRepository::class),
            new ToolExecutionResultStore(),
            new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
        );

        $result = $worker($call);
        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertFalse($result->isError, (string) ($result->result['content'][0]['text'] ?? json_encode($result->result)));
        $text = (string) ($result->result['content'][0]['text'] ?? '');
        $this->assertStringContainsString('42', $text);
        $this->assertCount(1, $commandBus->messages);
        $this->assertSame($result, $commandBus->messages[0]);
    }

    private function registerDoctrineCorrelation(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $repository = self::getContainer()->get(DeferredToolCompletionRepository::class);
        if (null === $repository->statusForRunAndToolCall($correlation->runId, $correlation->toolCallId)) {
            TestToolBatchRegistration::registerDeferredInvocation(self::getContainer()->get(ToolBatchStoreInterface::class), $correlation);
        }

        return $repository->registerPending($correlation);
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
