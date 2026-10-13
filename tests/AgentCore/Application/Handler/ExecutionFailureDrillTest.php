<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\Fake\FakePlatform;
use Ineersa\AgentCore\Tests\Support\Fake\FakeToolExecutor;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use PHPUnit\Framework\TestCase;

/**
 * Worker-level typed-result drills.
 *
 * Deleted ambient command-bus dispatch-failure cases are retained by:
 * - ExecutionAuthorizationMiddlewareTest::testReferenceTransportIsSmallAndNotificationFailureReusesDurableResult
 * - ExecutionAuthorizationMiddlewareTest::testHandlerCannotAcknowledgeWithoutDurableResultAndRunningRedeliveryDoesNotInvokeAgain
 * - CommandMailboxPolicyTest FIFO drain cases (testTurnStartDrainsAllQueuedSteersFifoWithOneLlmContinuation,
 *   testStopBoundaryDrainsAllQueuedSteersFifoWithOneAdvanceRun)
 * - CommandMailboxPolicyTest::testMissingAndMalformedMessageEnvelopesAreRejectedWithExactReasons (cutoff/order)
 */
final class ExecutionFailureDrillTest extends TestCase
{
    public function testLlmWorkerReturnsTerminalResultForOwnerDelivery(): void
    {
        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: SymfonyAiTestMessages::assistantText('first-attempt'),
                usage: ['total_tokens' => 4],
                stopReason: 'stop',
                error: null,
            ),
        ]);

        $message = new ExecuteLlmStep(
            runId: 'run-failure-worker-1',
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-failure-worker-1',
            toolsRef: 'toolset:run:run-failure-worker-1:turn:1',
        );

        $result = (new ExecuteLlmStepWorker(commandBus: new \Ineersa\AgentCore\Tests\Support\TestMessageBus(), platform: $platform))($message);
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertSame('first-attempt', $result->assistantMessage?->asText());
    }

    public function testToolWorkerReturnsTypedResultAfterSuccessfulExecution(): void
    {
        $toolExecutor = new FakeToolExecutor([
            'web_search' => static fn (): ToolResult => new ToolResult(
                toolCallId: 'call-1',
                toolName: 'web_search',
                content: [[
                    'type' => 'text',
                    'text' => 'ok',
                ]],
                details: ['source' => 'fake'],
                isError: false,
            ),
        ]);

        $message = new ExecuteToolCall(
            runId: 'run-failure-worker-2',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'tool-failure-worker-1',
            toolCallId: 'call-1',
            toolName: 'web_search',
            args: ['query' => 'symfony'],
            orderIndex: 0,
        );

        $worker = new ExecuteToolCallWorker(commandBus: new \Ineersa\AgentCore\Tests\Support\TestMessageBus(), logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            toolExecutor: $toolExecutor,
            deferredToolCompletionRepository: new InMemoryDeferredToolCompletionRepository(),
            resultStore: new ToolExecutionResultStore(),
            statusReader: new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
        );

        $result = $worker($message);
        $this->assertInstanceOf(ToolCallResult::class, $result);
        $this->assertSame('web_search', $result->result['tool_name']);
        $this->assertFalse($result->isError);
    }
}
