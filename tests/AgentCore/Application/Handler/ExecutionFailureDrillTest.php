<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
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
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecutionFailureDrillTest extends TestCase
{
    public function testLlmWorkerCanBeRetriedAfterCommandBusDispatchCrash(): void
    {
        $platform = new FakePlatform([
            new PlatformInvocationResult(
                assistantMessage: SymfonyAiTestMessages::assistantText('first-attempt'),
                usage: ['total_tokens' => 4],
                stopReason: 'stop',
                error: null,
            ),
            new PlatformInvocationResult(
                assistantMessage: SymfonyAiTestMessages::assistantText('retry-attempt'),
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
            contextRef: 'hot:run:run-failure-worker-1',
            toolsRef: 'toolset:run:run-failure-worker-1:turn:1',
            model: 'test-model',
        );

        $failingWorker = new ExecuteLlmStepWorker(
            platform: $platform,
            commandBus: new FailingOnceMessageBus(new TransportException('simulated dispatch crash')),
        );

        try {
            $failingWorker($message);
            $this->fail('Expected dispatch crash to bubble as RuntimeException.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed to dispatch LLM result to command bus.', $exception->getMessage());
        }

        $collectingBus = new TestMessageBus();
        $retryWorker = new ExecuteLlmStepWorker($platform, $collectingBus);
        $retryWorker($message);

        $this->assertCount(1, $collectingBus->messages);
        $this->assertInstanceOf(LlmStepResult::class, $collectingBus->messages[0]);

        /** @var LlmStepResult $result */
        $result = $collectingBus->messages[0];
        $this->assertSame('retry-attempt', $result->assistantMessage?->asText());
    }

    public function testToolWorkerCanBeRetriedAfterCommandBusDispatchCrash(): void
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

        $failingWorker = new ExecuteToolCallWorker(
            toolExecutor: $toolExecutor,
            commandBus: new FailingOnceMessageBus(new TransportException('simulated dispatch crash')),
            deferredToolCompletionRepository: new InMemoryDeferredToolCompletionRepository(),
        );

        try {
            $failingWorker($message);
            $this->fail('Expected dispatch crash to bubble as RuntimeException.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Failed to dispatch tool result to command bus.', $exception->getMessage());
        }

        $collectingBus = new TestMessageBus();
        $retryWorker = new ExecuteToolCallWorker($toolExecutor, $collectingBus, new InMemoryDeferredToolCompletionRepository());
        $retryWorker($message);

        $this->assertCount(1, $collectingBus->messages);
        $this->assertInstanceOf(ToolCallResult::class, $collectingBus->messages[0]);

        /** @var ToolCallResult $result */
        $result = $collectingBus->messages[0];
        $this->assertSame('web_search', $result->result['tool_name']);
        $this->assertFalse($result->isError);
    }
}

final class FailingOnceMessageBus implements MessageBusInterface
{
    private bool $failed = false;

    public function __construct(private readonly TransportException $exception)
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        if (!$this->failed) {
            $this->failed = true;

            throw $this->exception;
        }

        return new Envelope($message, $stamps);
    }
}
