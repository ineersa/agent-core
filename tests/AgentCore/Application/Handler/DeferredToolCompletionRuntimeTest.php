<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;

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
                    details: [
                        'raw_result' => new DeferredToolCompletionOutcome(reason: 'piece-2'),
                        'deferred_tool_completion' => true,
                    ],
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
                    details: ['raw_result' => new DeferredToolCompletionOutcome()],
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

    public function testCompleteDeferredToolCallDispatchesCanonicalResultFromStoredCorrelation(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [['type' => 'text', 'text' => 'deferred']],
                    details: ['raw_result' => new DeferredToolCompletionOutcome()],
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
            runId: 'run-deferred-1',
            turnNo: 99,
            stepId: 'wrong-step',
            attempt: 99,
            idempotencyKey: 'wrong-key',
            deferredId: $pending->deferredId,
            toolCallId: 'call-complete',
            toolName: 'web_search',
            content: [['type' => 'text', 'text' => 'final']],
            details: ['done' => true],
            isError: false,
            error: null,
            toolIdempotencyKey: 'tool-invocation-1',
            mode: 'ignored',
            arguments: ['ignored' => true],
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
        $this->assertSame(1, $result->orderIndex);
        $this->assertSame('parallel', $result->result['mode']);
        $this->assertSame(['query' => 'x'], $result->result['arguments']);
        $this->assertSame('final', $result->result['content'][0]['text']);
    }

    public function testDuplicateCompletionDispatchesAtMostOneObservableResult(): void
    {
        $repo = new InMemoryDeferredToolCompletionRepository();
        $correlation = $repo->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
            deferredId: 'def-dup-1',
            runId: 'run-deferred-1',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 2,
            idempotencyKey: 'tool-idemp-immediate',
            toolCallId: 'call-dup',
            toolName: 'web_search',
            arguments: ['query' => 'x'],
            orderIndex: 1,
            mode: 'parallel',
            timeoutSeconds: 120,
        ));

        $bus = new TestMessageBus();
        $handler = new CompleteDeferredToolCallHandler($repo, $bus, new TestLogger());

        $complete = new CompleteDeferredToolCall(
            runId: 'run-deferred-1',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 2,
            idempotencyKey: 'complete-key',
            deferredId: $correlation->deferredId,
            toolCallId: 'call-dup',
            toolName: 'web_search',
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
            runId: 'run-deferred-1',
            turnNo: 3,
            stepId: 'turn-3-tools-1',
            attempt: 2,
            idempotencyKey: 'complete-key',
            deferredId: 'missing-id',
            toolCallId: 'call-missing',
            toolName: 'web_search',
            content: [['type' => 'text', 'text' => 'nope']],
            isError: false,
        ));
    }

    public function testDoctrineRepositoryPersistsPendingCorrelation(): void
    {
        /** @var DeferredToolCompletionRepository $repo */
        $repo = self::getContainer()->get(DeferredToolCompletionRepository::class);

        $correlation = $repo->registerPending(new \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation(
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
}
