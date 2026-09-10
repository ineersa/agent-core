<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

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
    public function testThinkingOnlyResultIsTerminalWithoutSeparateWorkerRetryBudget(): void
    {
        // Shared application retry budget lives in LlmPlatformAdapter.
        // Worker stubs receive already-final platform results and must not re-invoke.
        $thinkingOnly = new AssistantMessage(new Thinking('reasoning...'));
        $platform = $this->createAlternatingPlatform([$thinkingOnly, new AssistantMessage(new Text('should-not-run'))]);
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();
        $worker = new ExecuteLlmStepWorker($platform, $testBus, logger: $testLogger);

        $worker(new ExecuteLlmStep(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            toolsRef: 'tools-1',
        ));

        $this->assertCount(1, $testBus->messages);
        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
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
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, logger: $testLogger);

        $worker(new ExecuteLlmStep(
            runId: 'run-3',
            turnNo: 1,
            stepId: 'step-3',
            attempt: 1,
            idempotencyKey: 'key-3',
            toolsRef: 'tools-3',
        ));

        $this->assertCount(1, $testBus->messages);

        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
        $this->assertNotNull($result->assistantMessage);
        $this->assertSame('Direct response', $result->assistantMessage->asText());
        $this->assertNull($result->error);

        // Normal path: exactly one platform invocation.
        $this->assertSame(1, $platform->invocationCount);

        // No retry warning logged.
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(0, $retryLogs, 'No retry warning when first attempt succeeds.');
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
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, logger: $testLogger);

        $worker(new ExecuteLlmStep(
            runId: 'run-4',
            turnNo: 1,
            stepId: 'step-4',
            attempt: 1,
            idempotencyKey: 'key-4',
            toolsRef: 'tools-4',
        ));

        $this->assertCount(1, $testBus->messages);

        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
        $this->assertNotNull($result->error, 'Provider error must be propagated.');
        $this->assertSame('provider_error', $result->error['type'] ?? null);
        $this->assertFalse($result->error['retryable'] ?? true);

        $this->assertSame(1, $platform->invocationCount);
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(0, $retryLogs, 'No thinking-only retry on provider error.');
    }

    public function testCommandBusDispatchFailureIsUnrecoverable(): void
    {
        $ok = new PlatformInvocationResult(
            assistantMessage: new AssistantMessage(new Text('ok')),
            usage: [],
            stopReason: 'stop',
        );
        $platform = $this->createAlternatingPlatform([$ok]);
        $commandBus = new class implements \Symfony\Component\Messenger\MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): \Symfony\Component\Messenger\Envelope
            {
                throw new TransportException('command bus unavailable');
            }
        };
        $worker = new ExecuteLlmStepWorker($platform, $commandBus, logger: new TestLogger());

        try {
            $worker(new ExecuteLlmStep(
                runId: 'run-dispatch-fail',
                turnNo: 1,
                stepId: 'step-dispatch-fail',
                attempt: 1,
                idempotencyKey: 'key-dispatch-fail',
                toolsRef: 'tools-dispatch-fail',
            ));
            $this->fail('Command-bus dispatch failure must throw UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $exception) {
            $this->assertStringContainsString('Failed to dispatch LLM result to command bus.', $exception->getMessage());
            $this->assertInstanceOf(TransportException::class, $exception->getPrevious());
        }

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
        $testBus = new TestMessageBus();
        $worker = new ExecuteLlmStepWorker($platform, $testBus, logger: new TestLogger());

        $worker(new ExecuteLlmStep(
            runId: 'run-retryable',
            turnNo: 2,
            stepId: 'step-retryable',
            attempt: 1,
            idempotencyKey: 'key-retryable',
            toolsRef: 'tools-ref',
        ));

        $this->assertCount(1, $testBus->messages);
        $result = $testBus->messages[0];
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertTrue($result->error['retry_exhausted'] ?? false);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame('openai-codex/gpt-5.6-luna', $result->model);
        $this->assertSame(1, $platform->invocationCount);
    }

    public function testForwardsCoordinatorPreparedMessagesToPlatform(): void
    {
        $platform = $this->createAlternatingPlatform([new AssistantMessage(new Text('done'))]);
        $worker = new ExecuteLlmStepWorker($platform, new TestMessageBus(), logger: new TestLogger());
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
                    deltas: [],
                    usage: ['input_tokens' => 100, 'output_tokens' => 20],
                    stopReason: 'stop',
                    error: null,
                    modelNotifications: [],
                );
            }
        };
    }
}
