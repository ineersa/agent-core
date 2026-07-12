<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
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

/**
 * Contract tests for {@see ExecuteLlmStepWorker}.
 *
 * Theses:
 *  - When the provider returns reasoning-only output (thinking, no text,
 *    no tool calls), the worker retries ONCE before conceding failure.
 *  - If the retry returns valid assistant content, the step succeeds with
 *    no error and the platform is invoked exactly twice.
 *  - If both attempts return reasoning-only, the step fails with
 *    empty_assistant_content and the platform is invoked exactly twice.
 *  - A single valid response proceeds normally (zero retries).
 */
final class ExecuteLlmStepWorkerTest extends TestCase
{
    public function testRetrySucceedsWhenFirstAttemptIsThinkingOnly(): void
    {
        $thinkingOnly = new AssistantMessage(new Thinking('reasoning...'));
        $validResponse = new AssistantMessage(new Text('Hello there'));

        $platform = $this->createAlternatingPlatform([$thinkingOnly, $validResponse]);
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, 'test-model', logger: $testLogger, runModelResolver: null);

        $worker(new ExecuteLlmStep(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            contextRef: 'ctx-1',
            toolsRef: 'tools-1',
        ));

        $this->assertCount(1, $testBus->messages);

        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertNotNull($result->assistantMessage, 'Retry must succeed with a valid assistant message.');
        $this->assertSame('Hello there', $result->assistantMessage->asText());
        $this->assertNull($result->error, 'No error when retry succeeds.');

        // Platform must have been invoked exactly twice.
        $this->assertSame(2, $platform->invocationCount);

        // A retry warning must be logged.
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(1, $retryLogs, 'Must log exactly one retry warning.');
    }

    public function testFailsWhenBothAttemptsAreThinkingOnly(): void
    {
        $thinkingOnly = new AssistantMessage(new Thinking('reasoning...again'));

        $platform = $this->createAlternatingPlatform([$thinkingOnly, $thinkingOnly]);
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, 'test-model', logger: $testLogger, runModelResolver: null);

        $worker(new ExecuteLlmStep(
            runId: 'run-2',
            turnNo: 1,
            stepId: 'step-2',
            attempt: 1,
            idempotencyKey: 'key-2',
            contextRef: 'ctx-2',
            toolsRef: 'tools-2',
        ));

        $this->assertCount(1, $testBus->messages);

        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
        $this->assertInstanceOf(LlmStepResult::class, $result);
        $this->assertNull($result->assistantMessage, 'Both attempts thinking-only: assistant must be null.');
        $this->assertNotNull($result->error, 'Both attempts thinking-only: must be an error.');
        $this->assertSame('empty_assistant_content', $result->error['type'] ?? null);
        $this->assertFalse($result->error['retryable'] ?? true, 'empty_assistant_content must be non-retryable.');

        // Platform must have been invoked exactly twice (not thrice).
        $this->assertSame(2, $platform->invocationCount);

        // Exactly one retry warning must be logged.
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(1, $retryLogs, 'Must log exactly one retry warning.');
    }

    public function testSingleValidResponseProceedsNormally(): void
    {
        $validResponse = new AssistantMessage(new Text('Direct response'));

        $platform = $this->createAlternatingPlatform([$validResponse]);
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, 'test-model', logger: $testLogger, runModelResolver: null);

        $worker(new ExecuteLlmStep(
            runId: 'run-3',
            turnNo: 1,
            stepId: 'step-3',
            attempt: 1,
            idempotencyKey: 'key-3',
            contextRef: 'ctx-3',
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

    public function testProviderErrorOnFirstAttemptIsNotRetried(): void
    {
        $errorResult = new PlatformInvocationResult(
            assistantMessage: null,
            usage: [],
            stopReason: null,
            error: ['type' => 'provider_error', 'message' => 'HTTP 500'],
        );

        $platform = $this->createAlternatingPlatform([$errorResult]);
        $testBus = new TestMessageBus();
        $testLogger = new TestLogger();

        $worker = new ExecuteLlmStepWorker($platform, $testBus, 'test-model', logger: $testLogger, runModelResolver: null);

        $worker(new ExecuteLlmStep(
            runId: 'run-4',
            turnNo: 1,
            stepId: 'step-4',
            attempt: 1,
            idempotencyKey: 'key-4',
            contextRef: 'ctx-4',
            toolsRef: 'tools-4',
        ));

        $this->assertCount(1, $testBus->messages);

        /** @var LlmStepResult $result */
        $result = $testBus->messages[0];
        $this->assertNotNull($result->error, 'Provider error must be propagated.');
        $this->assertSame('provider_error', $result->error['type'] ?? null);

        // Provider errors are NOT retried: only thinking-only responses are.
        $this->assertSame(1, $platform->invocationCount);

        // No retry warning.
        $retryLogs = $this->filterLogsByEventType($testLogger, 'llm.request.retrying_thinking_only');
        $this->assertCount(0, $retryLogs, 'No retry on provider error.');
    }

    public function testInvokesPlatformWithResolvedActiveModel(): void
    {
        $validResponse = new AssistantMessage(new Text('ok'));
        $platform = $this->createAlternatingPlatform([$validResponse]);
        $testBus = new TestMessageBus();

        $resolver = new class implements \Ineersa\AgentCore\Contract\Model\RunModelResolverInterface {
            public function resolveActiveModel(string $runId): ?string
            {
                return 'llama_cpp/flash';
            }
        };

        $worker = new ExecuteLlmStepWorker(
            $platform,
            $testBus,
            '',
            logger: new TestLogger(),
            runModelResolver: $resolver,
        );

        $worker(new ExecuteLlmStep(
            runId: 'session-42',
            turnNo: 1,
            stepId: 'step-vision',
            attempt: 1,
            idempotencyKey: 'key-vision',
            contextRef: 'ctx',
            toolsRef: 'tools',
        ));

        $this->assertSame('llama_cpp/flash', $platform->lastRequestModel);
    }

    public function testThinkingOnlyRetryReusesResolvedModel(): void
    {
        $thinkingOnly = new AssistantMessage(new Thinking('reasoning'));
        $validResponse = new AssistantMessage(new Text('recovered'));
        $platform = $this->createAlternatingPlatform([$thinkingOnly, $validResponse]);
        $testBus = new TestMessageBus();

        $resolver = new class implements \Ineersa\AgentCore\Contract\Model\RunModelResolverInterface {
            public function resolveActiveModel(string $runId): ?string
            {
                return 'runpod/Qwen3.6-27B';
            }
        };

        $worker = new ExecuteLlmStepWorker(
            $platform,
            $testBus,
            '',
            logger: new TestLogger(),
            runModelResolver: $resolver,
        );

        $worker(new ExecuteLlmStep(
            runId: 'session-retry',
            turnNo: 1,
            stepId: 'step-retry',
            attempt: 1,
            idempotencyKey: 'key-retry',
            contextRef: 'ctx',
            toolsRef: 'tools',
        ));

        $this->assertSame(2, $platform->invocationCount);
        $this->assertSame(['runpod/Qwen3.6-27B', 'runpod/Qwen3.6-27B'], $platform->requestModels);
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
