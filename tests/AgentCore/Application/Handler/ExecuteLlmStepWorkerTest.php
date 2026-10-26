<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * Contract tests for {@see ExecuteLlmStepWorker}.
 *
 * Theses:
 *  - When the provider returns reasoning-only output (thinking, no text,
 *    no tool calls), the worker retries ONCE before conceding failure.
 *  - If the retry returns valid assistant content, the step succeeds with
 *    no error and the platform is invoked exactly twice.
 *  - If both attempts return reasoning-only, the step fails with
 *    empty_assistant_content is terminal at the worker (adapter owns recovery).
 *  - A single valid response proceeds normally (zero retries).
 */
final class ExecuteLlmStepWorkerTest extends TestCase
{
    public function testOverlappingProviderStreamsKeepWorkerStateAndCancellationIsolated(): void
    {
        // Real worker, adapter, converter, Symfony Platform/Provider and stream builder.
        // Only the model client and result converter are boundaries; no HTTP is used.
        $logger = new TestLogger();
        $tracer = new RunTracer($logger);
        $bus = new TestMessageBus();
        $payloads = $events = $references = [];
        $client = $this->createStub(ModelClientInterface::class);
        $client->method('supports')->willReturn(true);
        $client->method('request')->willReturnCallback(static function ($model, $payload) use (&$payloads): RawResultInterface {
            $run = LlmInvocationCancelScope::currentRunId();
            self::assertIsString($run);
            // Provider HTTP/auth may yield here, after Symfony normalized the input.
            \Fiber::suspend('request-'.$run);
            self::assertSame($run, LlmInvocationCancelScope::currentRunId());
            $payloads[$run] = json_encode($payload, \JSON_THROW_ON_ERROR);

            return new InMemoryRawResult(['run' => $run]);
        });
        $converter = $this->createStub(ResultConverterInterface::class);
        $converter->method('supports')->willReturn(true);
        $converter->method('convert')->willReturnCallback(static function (RawResultInterface $raw) use ($tracer, &$references): StreamResult {
            $run = $raw->getData()['run'];

            return new StreamResult((static function () use ($run, $tracer, &$references): \Generator {
                $streamLifetime = new \stdClass();
                $references[$run] = \WeakReference::create($streamLifetime);
                yield new ThinkingDelta('thinking-'.$run);
                yield new TextDelta('partial-'.$run);
                $tracer->inSpan('provider.wait', ['run_id' => $run], static function () use ($run): void {
                    self::assertSame($run, LlmInvocationCancelScope::currentRunId());
                    self::assertSame($run, RunLogContext::current()['run_id']);
                    self::assertSame($run, RunLogContext::current()['session_id']);
                    \Fiber::suspend($run);
                    self::assertSame($run, LlmInvocationCancelScope::currentRunId());
                    self::assertSame($run, RunLogContext::current()['run_id']);
                });
                if ('run-a' === $run) {
                    throw new LlmStreamCancelledException('Controlled cancellation of A');
                }
                yield new TextDelta('-finished');
            })());
        });
        $observer = $this->createStub(LlmStreamObserverInterface::class);
        foreach (['onStreamStart', 'onDelta', 'onStreamEnd', 'onStreamError'] as $method) {
            $observer->method($method)->willReturnCallback(static function (string $run, ?string $step, mixed $detail = null) use (&$events, $method): void {
                $events[] = [$method, $run, $step, $detail instanceof DeltaInterface ? $detail::class : null,
                    LlmInvocationCancelScope::currentRunId(), RunLogContext::current(),
                ];
            });
        }
        $resolver = $this->createStub(ModelResolverInterface::class);
        $resolver->method('resolve')->willReturn(new ResolvedModel('overlap-test'));
        $adapter = new LlmPlatformAdapter(
            statusReader: new NullRunOperationalStatusReader(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: new Platform([new Provider('controlled', [$client], [$converter], new FallbackModelCatalog())]),
            transformContextHooks: [], convertToLlmHooks: [], streamObserver: $observer,
            costCalculator: null, logger: $logger,
            denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
            modelResolver: $resolver,
        );
        $worker = new ExecuteLlmStepWorker($bus, $adapter, logger: $logger, tracer: $tracer);
        $prefix = new AgentMessage('user', [['type' => 'text', 'text' => 'prefix-a']]);
        $invoke = static function (string $id, array $messages) use ($worker): LlmStepResult {
            $result = $worker(new ExecuteLlmStep('run-'.$id, 1, 'step-'.$id, 1, 'key-'.$id, 'tools-'.$id, $messages));
            self::assertSame([], RunLogContext::current());
            self::assertNull(LlmInvocationCancelScope::currentRunId());

            return $result;
        };
        $a = new \Fiber(static fn () => $invoke('a', [$prefix]));
        // Same object prefix intentionally exercises the converter's in-place cache append.
        $b = new \Fiber(static fn () => $invoke('b', [$prefix, new AgentMessage('user', [['type' => 'text', 'text' => 'suffix-b']])]));
        $aReference = \WeakReference::create($a);
        $bReference = \WeakReference::create($b);
        $this->assertSame('request-run-a', $a->start());
        $this->assertSame('request-run-b', $b->start());
        $this->assertSame('run-a', $a->resume());
        $this->assertSame('run-b', $b->resume());
        $this->assertSame([], RunLogContext::current());
        $this->assertNull(LlmInvocationCancelScope::currentRunId());
        $a->resume();
        $this->assertTrue($a->isTerminated());
        $this->assertTrue($b->isSuspended());
        $b->resume();
        $aResult = $a->getReturn();
        $bResult = $b->getReturn();
        $this->assertSame('aborted', $aResult->stopReason);
        $this->assertNull($aResult->error);
        $this->assertSame('partial-run-a', $aResult->assistantMessage?->asText());
        $this->assertSame('thinking-run-a', $aResult->assistantMessage?->getThinking()[0]->getContent());
        $this->assertNull($bResult->error);
        $this->assertNotSame('aborted', $bResult->stopReason);
        $this->assertSame('partial-run-b-finished', $bResult->assistantMessage?->asText());
        $this->assertSame('thinking-run-b', $bResult->assistantMessage?->getThinking()[0]->getContent());
        $this->assertSame([$aResult, $bResult], $bus->messages);
        $this->assertSame('run-a', $aResult->runId());
        $this->assertSame('step-b', $bResult->stepId());
        $this->assertStringNotContainsString('suffix-b', $payloads['run-a']);
        $this->assertStringContainsString('suffix-b', $payloads['run-b']);
        $this->assertSame([
            'onStreamStart', 'onDelta', 'onDelta',
            'onStreamStart', 'onDelta', 'onDelta',
            'onStreamEnd', 'onDelta', 'onStreamEnd',
        ], array_column($events, 0));
        foreach ($events as $event) {
            $this->assertSame('step-'.substr($event[1], -1), $event[2]);
            $this->assertSame($event[1], $event[4]);
            $this->assertSame($event[1], $event[5]['run_id']);
            $this->assertSame($event[1], $event[5]['session_id']);
        }
        $starts = array_values(array_filter($logger->records, static fn (array $record): bool => 'agent_loop.trace.start' === $record['message']));
        $this->assertCount(6, $starts);
        $this->assertSame($starts[0]['context']['span_id'], $starts[1]['context']['parent_span_id']);
        $this->assertSame($starts[2]['context']['span_id'], $starts[3]['context']['parent_span_id']);
        $this->assertSame($starts[1]['context']['span_id'], $starts[4]['context']['parent_span_id']);
        $this->assertSame($starts[3]['context']['span_id'], $starts[5]['context']['parent_span_id']);
        $this->assertNull($starts[2]['context']['parent_span_id']);
        foreach ($references as $reference) {
            $this->assertNull($reference->get(), 'Completed invocations must release stream-local references.');
        }
        unset($a, $b);
        $this->assertNull($aReference->get());
        $this->assertNull($bReference->get());
    }

    public function testThinkingOnlyResultIsTerminalWithoutSeparateWorkerRetryBudget(): void
    {
        // Shared application retry budget lives in LlmPlatformAdapter.
        // Worker stubs receive already-final platform results and must not re-invoke.
        $thinkingOnly = new AssistantMessage(new Thinking('reasoning...'));
        $platform = $this->createAlternatingPlatform([$thinkingOnly, new AssistantMessage(new Text('should-not-run'))]);
        $testLogger = new TestLogger();
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: $testLogger);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolsRef: 'tools-1',
        ));
        $this->assertNull($result->assistantMessage);
        $this->assertSame('empty_assistant_content', $result->error['type'] ?? null);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame(1, $platform->invocationCount);
        $this->assertCount(0, $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only'));
    }

    public function testSingleValidResponseProceedsNormally(): void
    {
        $validResponse = new AssistantMessage(new Text('Direct response'));

        $platform = $this->createAlternatingPlatform([$validResponse]);
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: $testLogger);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-3',
            turnNo: 1,
            stepId: 'step-3',
            attempt: 1,
            idempotencyKey: 'key-3',
            toolsRef: 'tools-3',
        ));
        $this->assertNotNull($result->assistantMessage);
        $this->assertSame('Direct response', $result->assistantMessage->asText());
        $this->assertNull($result->error);

        // Normal path: exactly one platform invocation.
        $this->assertSame(1, $platform->invocationCount);

        // No retry warning logged.
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(0, $retryLogs, 'No retry warning when first attempt succeeds.');
    }

    public function testReceivedDeltaFactPreservesEmptyStreamDistinction(): void
    {
        foreach ([false, true] as $receivedStreamDeltas) {
            $platform = $this->createAlternatingPlatform([new PlatformInvocationResult(
                assistantMessage: null,
                receivedStreamDeltas: $receivedStreamDeltas,
                stopReason: 'stop',
            )]);
            $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: new TestLogger());
            $result = $worker(new ExecuteLlmStep(
                runId: 'synthetic-empty-stream', turnNo: 1, stepId: 'step', attempt: 1, idempotencyKey: 'key', toolsRef: 'tools',
            ));
            if ($receivedStreamDeltas) {
                $this->assertNull($result->error);
            } else {
                $this->assertSame('empty_response', $result->error['type'] ?? null);
            }
            $this->assertSame(1, $platform->invocationCount);
        }
    }

    public function testNonRetryableProviderErrorDispatchesTerminalResultWithoutMessengerRetrySignal(): void
    {
        $errorResult = new PlatformInvocationResult(
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: ['type' => 'provider_error', 'message' => 'HTTP 500', 'retryable' => false],
        );

        $platform = $this->createAlternatingPlatform([$errorResult]);
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: $testLogger);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-4',
            turnNo: 1,
            stepId: 'step-4',
            attempt: 1,
            idempotencyKey: 'key-4',
            toolsRef: 'tools-4',
        ));
        $this->assertNotNull($result->error, 'Provider error must be propagated.');
        $this->assertSame('provider_error', $result->error['type'] ?? null);
        $this->assertFalse($result->error['retryable'] ?? true);

        $this->assertSame(1, $platform->invocationCount);
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(0, $retryLogs, 'No thinking-only retry on provider error.');
    }

    public function testZeroDeltaAbortedResultPreservesShapeAndLogsCancellation(): void
    {
        $aborted = new PlatformInvocationResult(
            assistantMessage: null,
            receivedStreamDeltas: false,
            usage: [],
            stopReason: 'aborted',
            error: null,
            model: 'fake/model',
            reasoning: 'low',
        );

        $platform = $this->createAlternatingPlatform([$aborted]);
        $testLogger = new TestLogger();
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: $testLogger);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-aborted-silence',
            turnNo: 1,
            stepId: 'step-aborted-silence',
            attempt: 1,
            idempotencyKey: 'key-aborted-silence',
            toolsRef: 'tools-aborted-silence',
        ));
        $this->assertSame('aborted', $result->stopReason);
        $this->assertNull($result->error);
        $this->assertNull($result->assistantMessage);
        $this->assertSame('fake/model', $result->model);

        $cancelledLogs = $this->filterLogsByEventType($testLogger, 'llm.request.cancelled');
        $this->assertCount(1, $cancelledLogs);
        $this->assertSame('llm.request.cancelled', $cancelledLogs[0]['message']);

        $failedLogs = $this->filterLogsByEventType($testLogger, 'llm.request.failed');
        $this->assertCount(0, $failedLogs, 'Aborted silence must not be logged as provider failure.');

        $completedLogs = $this->filterLogsByEventType($testLogger, 'llm.request.completed');
        $this->assertCount(0, $completedLogs, 'Aborted silence must not be logged as completion.');
    }

    public function testThinkingOnlyAbortedResultPreservesPartialMessageAndLogsCancellation(): void
    {
        $thinkingOnly = new AssistantMessage(new Thinking('partial reasoning before cancel'));
        $aborted = new PlatformInvocationResult(
            assistantMessage: $thinkingOnly,
            receivedStreamDeltas: false,
            usage: [],
            stopReason: 'aborted',
            error: null,
            model: 'fake/model',
            reasoning: 'low',
        );

        $platform = $this->createAlternatingPlatform([$aborted]);
        $testLogger = new TestLogger();
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: $testLogger);

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-aborted-thinking',
            turnNo: 1,
            stepId: 'step-aborted-thinking',
            attempt: 1,
            idempotencyKey: 'key-aborted-thinking',
            toolsRef: 'tools-aborted-thinking',
        ));
        $this->assertSame('aborted', $result->stopReason);
        $this->assertNull($result->error);
        $this->assertSame($thinkingOnly, $result->assistantMessage);
        $this->assertSame('fake/model', $result->model);

        $cancelledLogs = $this->filterLogsByEventType($testLogger, 'llm.request.cancelled');
        $this->assertCount(1, $cancelledLogs);
        $this->assertSame('llm.request.cancelled', $cancelledLogs[0]['message']);

        $failedLogs = $this->filterLogsByEventType($testLogger, 'llm.request.failed');
        $this->assertCount(0, $failedLogs, 'Aborted thinking-only must not be logged as provider failure.');

        $completedLogs = $this->filterLogsByEventType($testLogger, 'llm.request.completed');
        $this->assertCount(0, $completedLogs, 'Aborted thinking-only must not be logged as completion.');
    }

    public function testWorkerReturnsTerminalLlmStepResultWithoutDispatching(): void
    {
        $ok = new PlatformInvocationResult(
            assistantMessage: new AssistantMessage(new Text('ok')),
            usage: [],
            stopReason: 'stop',
        );
        $platform = $this->createAlternatingPlatform([$ok]);
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: new TestLogger());

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-dispatch-fail',
            turnNo: 1,
            stepId: 'step-dispatch-fail',
            attempt: 1,
            idempotencyKey: 'key-dispatch-fail',
            toolsRef: 'tools-dispatch-fail',
        ));

        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertSame('ok', $result->assistantMessage?->asText());
        $this->assertSame(1, $platform->invocationCount);
    }

    public function testProviderErrorDispatchesTerminalLlmStepResultWithoutMessengerRetryThrow(): void
    {
        // Application retry budget is owned by LlmPlatformAdapter. The worker
        // always posts LlmStepResult; it must not throw for retryable flags.
        $errorResult = new PlatformInvocationResult(
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: [
                'type' => 'provider_error',
                'message' => 'Codex WebSocket idle timeout.',
                'retryable' => false,
                'retry_exhausted' => true,
                'error_category' => 'provider',
                'user_message' => 'LLM provider request failed after retries were exhausted.',
            ],
            model: 'openai-codex/gpt-5.6-luna',
            reasoning: 'medium',
            availableTools: ['bash', 'edit'],
            availableToolsSchemaTokensEstimate: 12,
        );

        $platform = $this->createAlternatingPlatform([$errorResult]);
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: new TestLogger());

        $result = $worker(new ExecuteLlmStep(
            runId: 'run-retryable',
            turnNo: 2,
            stepId: 'step-retryable',
            attempt: 1,
            idempotencyKey: 'key-retryable',
            toolsRef: 'tools-ref',
        ));
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertTrue($result->error['retry_exhausted'] ?? false);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame('openai-codex/gpt-5.6-luna', $result->model);
        $this->assertSame(1, $platform->invocationCount);
    }

    public function testForwardsCoordinatorPreparedMessagesToPlatform(): void
    {
        $platform = $this->createAlternatingPlatform([new AssistantMessage(new Text('done'))]);
        $worker = new ExecuteLlmStepWorker(new TestMessageBus(), $platform, logger: new TestLogger());
        $messages = [new AgentMessage('user', [['type' => 'text', 'text' => 'private coordinator context']])];

        $worker(new ExecuteLlmStep(
            runId: 'run-direct-context',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolsRef: 'tools-1',
            messages: $messages,
        ));

        $this->assertSame($messages, $platform->requests[0]->input->messages);
    }

    // ── helpers ──

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    private function filterLogsByEventType(TestLogger $logger, string $eventType): array
    {
        return array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => ($record['context']['event_type'] ?? '') === $eventType,
        ));
    }

    /**
     * Creates a PlatformInterface that returns the given messages in sequence.
     * After all messages are consumed, it returns the last one repeatedly.
     *
     * @param list<AssistantMessage|PlatformInvocationResult> $responses
     */
    private function createAlternatingPlatform(array $responses): object
    {
        return new class($responses) implements PlatformInterface {
            public int $invocationCount = 0;

            public ?string $lastRequestModel = null;

            /** @var list<string> */
            public array $requestModels = [];

            /** @var list<ModelInvocationRequest> */
            public array $requests = [];

            /** @var list<AssistantMessage|PlatformInvocationResult> */
            private array $responses;

            /**
             * @param list<AssistantMessage|PlatformInvocationResult> $responses
             */
            public function __construct(array $responses)
            {
                $this->responses = $responses;
            }

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                $index = $this->invocationCount;
                ++$this->invocationCount;
                $this->lastRequestModel = $request->model;
                $this->requestModels[] = $request->model;
                $this->requests[] = $request;

                $item = $this->responses[min($index, \count($this->responses) - 1)];

                if ($item instanceof PlatformInvocationResult) {
                    return $item;
                }

                // Wrap an AssistantMessage in a successful PlatformInvocationResult.
                return new PlatformInvocationResult(
                    assistantMessage: $item,
                    receivedStreamDeltas: false,
                    usage: ['input_tokens' => 100, 'output_tokens' => 20],
                    stopReason: 'stop',
                    error: null,
                    modelNotifications: [],
                );
            }
        };
    }
}
