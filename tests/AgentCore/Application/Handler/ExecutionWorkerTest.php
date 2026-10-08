<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException;
use Ineersa\AgentCore\Tests\Support\Fake\FakeToolExecutor;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

final class ExecutionWorkerTest extends TestCase
{
    public function testLlmWorkerConvertsPlatformErrorsIntoStructuredResultMessage(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                throw new \RuntimeException('Provider unavailable.');
            }
        };

        $worker = new ExecuteLlmStepWorker($platform);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-worker-1',
            turnNo: 4,
            stepId: 'turn-4-llm-1',
            attempt: 2,
            idempotencyKey: 'llm-idemp-1',
            toolsRef: 'toolset:run:run-worker-1:turn:4',
        ));

        $this->assertSame('run-worker-1', $result->runId());
        $this->assertSame('turn-4-llm-1', $result->stepId());
        $this->assertNotNull($result->error);
        $this->assertSame('Provider unavailable.', $result->error['message']);
    }

    public function testLlmWorkerConvertsMalformedToolCallSequenceExceptionToStructuredError(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                throw MalformedToolCallSequenceException::unclosedBatch(2, 'user', 1, ['tc-1']);
            }
        };

        $worker = new ExecuteLlmStepWorker($platform);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-malformed-1',
            turnNo: 3,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-malformed-1',
            toolsRef: 'toolset:run:run-malformed-1:turn:3',
        ));

        $this->assertSame('run-malformed-1', $result->runId());
        $this->assertSame('turn-3-llm-1', $result->stepId());
        $this->assertSame('error', $result->stopReason);
        $this->assertNotNull($result->error);
        // The exception message is preserved in the LlmStepResult error.
        $this->assertStringContainsString('Tool-call sequence violation', $result->error['message'] ?? '');
        $this->assertStringContainsString('MalformedToolCallSequenceException', $result->error['type'] ?? '');
    }

    public function testLlmWorkerRecordsFailureTracingSpan(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                throw new \RuntimeException('Provider unavailable.');
            }
        };

        $traceLogger = new TestLogger();
        $tracer = new RunTracer($traceLogger);

        $worker = new ExecuteLlmStepWorker($platform, tracer: $tracer);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-worker-obs-1',
            turnNo: 2,
            stepId: 'turn-2-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-obs-1',
            toolsRef: 'toolset:run:run-worker-obs-1:turn:2',
        ));

        $llmFinishSpans = array_values(array_filter(
            $traceLogger->records,
            static fn (array $record): bool => 'agent_loop.trace.finish' === $record['message']
                && 'llm.call' === ($record['context']['span_name'] ?? null),
        ));

        $this->assertCount(1, $llmFinishSpans);
        $this->assertSame('error', $llmFinishSpans[0]['context']['status']);
    }

    public function testToolWorkerDispatchesToolCallResult(): void
    {
        $toolExecutor = new class implements ToolExecutorInterface {
            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult(
                    toolCallId: $toolCall->toolCallId,
                    toolName: $toolCall->toolName,
                    content: [[
                        'type' => 'text',
                        'text' => 'ok',
                    ]],
                    details: ['echo' => $toolCall->arguments],
                    isError: false,
                );
            }
        };

        $worker = new ExecuteToolCallWorker($toolExecutor, new InMemoryDeferredToolCompletionRepository(), new ToolExecutionResultStore(), new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader());

        $result = $worker(new ExecuteToolCall(
            runId: 'run-worker-2',
            turnNo: 2,
            stepId: 'turn-2-tools-1',
            attempt: 1,
            idempotencyKey: 'tool-idemp-1',
            toolCallId: 'call-1',
            toolName: 'web_search',
            args: ['query' => 'symfony lock'],
            orderIndex: 0,
            toolIdempotencyKey: 'tool-invocation-1',
        ));

        $this->assertSame('call-1', $result->toolCallId);
        $this->assertFalse($result->isError);
        $this->assertSame('web_search', $result->result['tool_name']);
    }

    public function testToolWorkerReleasesCompletedResultAfterSuccessfulReturn(): void
    {
        $store = new ToolExecutionResultStore();
        $stored = new ToolResult(
            toolCallId: 'call-release-1',
            toolName: 'web_search',
            content: [['type' => 'text', 'text' => 'stored']],
            details: [],
            isError: false,
        );
        $store->remember('run-release-1', 'call-release-1', 'web_search', 'tool-release-1', $stored);

        $worker = new ExecuteToolCallWorker(
            new FakeToolExecutor(),
            new InMemoryDeferredToolCompletionRepository(),
            $store,
            new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
        );

        $result = $worker(new ExecuteToolCall(
            runId: 'run-release-1',
            turnNo: 1,
            stepId: 'turn-1-tools-1',
            attempt: 1,
            idempotencyKey: 'tool-release-1',
            toolCallId: 'call-release-1',
            toolName: 'web_search',
            args: [],
            orderIndex: 0,
            toolIdempotencyKey: 'tool-release-1',
        ));

        $this->assertNull($store->findByRunToolCall('run-release-1', 'call-release-1'));
        $this->assertNull($store->findByToolAndIdempotencyKey('web_search', 'tool-release-1'));
    }

    public function testToolWorkerReleasesDeferredMarkerAfterDurableRegistration(): void
    {
        $store = new ToolExecutionResultStore();
        $marker = new ToolResult(
            toolCallId: 'call-deferred-1',
            toolName: 'fork',
            content: [],
            details: ['raw_result' => new DeferredToolCompletionOutcome('deferred-1')],
            isError: false,
        );
        $store->remember('run-deferred-1', 'call-deferred-1', 'fork', 'tool-deferred-1', $marker);
        $executor = new class($marker) implements ToolExecutorInterface {
            public function __construct(private ToolResult $result)
            {
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return $this->result;
            }
        };

        $worker = new ExecuteToolCallWorker(
            $executor,
            new InMemoryDeferredToolCompletionRepository(),
            $store,
            new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
        );
        $worker(new ExecuteToolCall(
            runId: 'run-deferred-1', turnNo: 1, stepId: 'step-1', attempt: 1,
            idempotencyKey: 'idempotency-1', toolCallId: 'call-deferred-1', toolName: 'fork',
            args: [], orderIndex: 0, toolIdempotencyKey: 'tool-deferred-1',
        ));

        $this->assertNull($store->findByRunToolCall('run-deferred-1', 'call-deferred-1'));
        $this->assertNull($store->findByToolAndIdempotencyKey('fork', 'tool-deferred-1'));
    }

    /**
     * Thesis: an empty platform response is detected BEFORE metrics
     * and logging, so the deficiency is counted as an error (not a
     * successful LLM call).  This prevents the "LLM placeholder response"
     * from being silently recorded as a successful call.
     */
    public function testLlmWorkerRecordsEmptyResponseAsErrorInMetricsAndLog(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                // Empty response: no assistant message, no deltas,
                // no stop reason, no error.
                return new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: [],
                    usage: ['input_tokens' => 100, 'output_tokens' => 0],
                    stopReason: null,
                    modelNotifications: [],
                    error: null,
                );
            }
        };

        $testLogger = new TestLogger();

        // Non-null logger passed so the worker logs (bypasses NullLogger default).
        $worker = new ExecuteLlmStepWorker($platform, logger: $testLogger);

        $worker(new ExecuteLlmStep(
            runId: 'run-empty-metrics-1',
            turnNo: 3,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-empty-metrics-1',
            toolsRef: 'toolset:run:run-empty-metrics-1:turn:3',
        ));

        // Logger: should emit llm.request.failed with error_type=empty_response,
        // NOT llm.request.completed.
        $failedLogs = array_values(array_filter(
            $testLogger->records,
            static fn (array $record): bool => 'llm.request.failed' === $record['message']
                && 'empty_response' === ($record['context']['error_type'] ?? null),
        ));
        $this->assertCount(1, $failedLogs, 'Empty response must log llm.request.failed with error_type=empty_response');

        $completedLogs = array_values(array_filter(
            $testLogger->records,
            static fn (array $record): bool => 'llm.request.completed' === $record['message'],
        ));
        $this->assertCount(0, $completedLogs, 'Empty response must NOT log llm.request.completed');
    }

    /**
     * Thesis: an empty platform response (no assistant message, no deltas,
     * no stop reason, no error) must produce an error LlmStepResult, NOT
     * a fabricated placeholder assistant message that enters the conversation
     * history.  This prevents the "LLM placeholder response for hot:run:X"
     * text from being stored as real assistant output.
     */
    public function testLlmWorkerConvertsEmptyPlatformResponseToError(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                // Empty response: no assistant message, no deltas,
                // no stop reason, no error.
                return new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: [],
                    usage: ['input_tokens' => 100, 'output_tokens' => 0],
                    stopReason: null,
                    modelNotifications: [],
                    error: null,
                );
            }
        };

        $worker = new ExecuteLlmStepWorker($platform);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-empty-1',
            turnNo: 3,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-empty-1',
            toolsRef: 'toolset:run:run-empty-1:turn:3',
        ));

        $this->assertSame('run-empty-1', $result->runId());
        $this->assertNull($result->assistantMessage, 'Empty platform response must not produce a fake assistant message');
        $this->assertNotNull($result->error, 'Empty platform response must be treated as an error');
        $this->assertSame('empty_response', $result->error['type'] ?? '');
        $this->assertStringContainsString('empty response', $result->error['message'] ?? '');
    }

    /**
     * Thesis: a finish_reason-only stream must not count as a successful LLM turn
     * merely because stopReason is now populated (Symfony AI 0.11 metadata).
     */
    public function testLlmWorkerTreatsFinishReasonOnlyResponseAsEmptyError(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                unset($request);

                return new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: [],
                    usage: ['input_tokens' => 10, 'output_tokens' => 0],
                    stopReason: 'stop',
                    modelNotifications: [],
                    error: null,
                );
            }
        };

        $worker = new ExecuteLlmStepWorker($platform);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-finish-only-1',
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-finish-only-1',
            toolsRef: 'toolset:run:run-finish-only-1:turn:1',
        ));
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertNull($result->assistantMessage);
        $this->assertNotNull($result->error);
        $this->assertSame('empty_response', $result->error['type'] ?? '');
    }
}
