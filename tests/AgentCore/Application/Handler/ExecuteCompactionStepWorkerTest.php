<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteCompactionStepWorker;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

/**
 * Contract tests for {@see ExecuteCompactionStepWorker}.
 *
 * Theses:
 *  - Invokes PlatformInterface with toolsEnabled:false, streamObserverEnabled:false, and explicit model+thinkingLevel.
 *  - Dispatches CompactionStepResult with summary text on success.
 *  - Dispatches CompactionStepResult with error on model failure.
 *  - Passes explicit model string through to the returned result.
 */
final class ExecuteCompactionStepWorkerTest extends TestCase
{
    public function testInvokesPlatformWithNoToolsAndExplicitModel(): void
    {
        $responseText = 'summary text';
        $assistantMsg = new AssistantMessage(new Text($responseText));
        
        // Verify the AssistantMessage returns text correctly.
        self::assertSame($responseText, $assistantMsg->asText(), 'AssistantMessage::asText() precondition');

        $fakePlatform = $this->createFakePlatform($responseText, model: 'openai/gpt-4.1-mini', captureRequest: true);
        $testBus = new TestMessageBus();

        $worker = new ExecuteCompactionStepWorker($fakePlatform, $testBus);
        $worker(new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-compact-1',
            attempt: 1,
            idempotencyKey: 'key-1',
            model: 'openai/gpt-4.1-mini',
            thinkingLevel: 'low',
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 10,
            messagesRetained: 5,
            firstRetainedIndex: 10,
            tokenEstimateBefore: 42000,
            trigger: 'manual',
        ));

        // Dispatched one message to the command bus.
        self::assertCount(1, $testBus->messages);

        /** @var CompactionStepResult $result */
        $result = $testBus->messages[0];
        self::assertInstanceOf(CompactionStepResult::class, $result);
        self::assertSame('summary text', $result->summaryText);
        self::assertNull($result->error);
        self::assertSame('openai/gpt-4.1-mini', $result->model);
        self::assertSame('low', $result->thinkingLevel);

        // Assert the captured ModelInvocationRequest has the correct options.
        $captured = $fakePlatform->lastRequest;
        self::assertNotNull($captured, 'Platform should have captured the request.');
        self::assertSame('openai/gpt-4.1-mini', $captured->model);
        self::assertFalse($captured->options->toolsEnabled, 'toolsEnabled must be false for compaction.');
        self::assertFalse($captured->options->streamObserverEnabled, 'streamObserverEnabled must be false for compaction.');
        self::assertSame('low', $captured->options->thinkingLevel);
        // Messages are direct messages, not null.
        self::assertNotNull($captured->input->messages);
        self::assertIsArray($captured->input->messages);
    }

    public function testExplicitModelPassedInResult(): void
    {
        $fakePlatform = $this->createFakePlatform('ok', model: 'llama_cpp/flash');
        $testBus = new TestMessageBus();

        $worker = new ExecuteCompactionStepWorker($fakePlatform, $testBus);
        $worker(new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-2',
            attempt: 1,
            idempotencyKey: 'key-2',
            model: 'llama_cpp/flash',
            thinkingLevel: null,
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            tokenEstimateBefore: 0,
            trigger: 'manual',
        ));

        self::assertCount(1, $testBus->messages);

        /** @var CompactionStepResult $result */
        $result = $testBus->messages[0];
        self::assertSame('llama_cpp/flash', $result->model);
        self::assertNull($result->thinkingLevel);
    }

    public function testModelErrorDispatchesResultWithError(): void
    {
        $errorPayload = ['type' => 'RuntimeException', 'message' => 'Simulated failure'];
        $fakePlatform = $this->createFakePlatformWithError($errorPayload);
        $testBus = new TestMessageBus();

        $worker = new ExecuteCompactionStepWorker($fakePlatform, $testBus);
        $worker(new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-3',
            attempt: 1,
            idempotencyKey: 'key-3',
            model: '',
            thinkingLevel: null,
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            tokenEstimateBefore: 0,
            trigger: 'manual',
        ));

        self::assertCount(1, $testBus->messages);

        /** @var CompactionStepResult $result */
        $result = $testBus->messages[0];
        self::assertNull($result->summaryText);
        self::assertNotNull($result->error);
        self::assertSame('RuntimeException', $result->error['type']);
        self::assertSame('Simulated failure', $result->error['message']);
    }

    public function testPlatformExceptionDispatchesErrorResult(): void
    {
        $fakePlatform = $this->createFakePlatformThatThrows(new \RuntimeException('Boom'));
        $testBus = new TestMessageBus();

        $worker = new ExecuteCompactionStepWorker($fakePlatform, $testBus);
        $worker(new ExecuteCompactionStep(
            runId: 'run-1',
            turnNo: 5,
            stepId: 'step-4',
            attempt: 1,
            idempotencyKey: 'key-4',
            model: '',
            thinkingLevel: null,
            summarizationMessages: [],
            retainedTailMessages: [],
            messagesCompacted: 0,
            messagesRetained: 0,
            firstRetainedIndex: 0,
            tokenEstimateBefore: 0,
            trigger: 'manual',
        ));

        self::assertCount(1, $testBus->messages);

        /** @var CompactionStepResult $result */
        $result = $testBus->messages[0];
        self::assertNull($result->summaryText);
        self::assertNotNull($result->error);
        self::assertSame(\RuntimeException::class, $result->error['type']);
    }

    // ── helpers ──

    private function createFakePlatform(string $responseText, string $model = '', bool $captureRequest = false): object
    {
        return new class($responseText, $model, $captureRequest) implements PlatformInterface {
            public ?ModelInvocationRequest $lastRequest = null;

            public function __construct(
                private string $responseText,
                private string $model,
                private bool $captureRequest,
            ) {}

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                if ($this->captureRequest) {
                    $this->lastRequest = $request;
                }
                $msg = new AssistantMessage(new Text($this->responseText));

                return new PlatformInvocationResult(
                    assistantMessage: $msg,
                    deltas: [],
                    usage: [],
                    stopReason: 'stop',
                    error: null,
                    modelNotifications: [],
                );
            }
        };
    }

    /**
     * @param array{type: string, message: string} $error
     */
    private function createFakePlatformWithError(array $error): PlatformInterface
    {
        return new class($error) implements PlatformInterface {
            public function __construct(private array $error) {}

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                return new PlatformInvocationResult(
                    assistantMessage: null,
                    deltas: [],
                    usage: [],
                    stopReason: null,
                    error: $this->error,
                    modelNotifications: [],
                );
            }
        };
    }

    private function createFakePlatformThatThrows(\Throwable $exception): PlatformInterface
    {
        return new class($exception) implements PlatformInterface {
            public function __construct(private \Throwable $exception) {}

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                throw $this->exception;
            }
        };
    }
}
