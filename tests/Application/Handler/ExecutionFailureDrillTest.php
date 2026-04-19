<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\Fake\FakePlatform;
use Ineersa\AgentCore\Tests\Support\Fake\FakeToolExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecutionFailureDrillTest extends TestCase
{
    public function testLlmWorkerCanBeRetriedAfterCommandBusDispatchCrash(): void
    {
        $platform = new FakePlatform([
            [
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'first-attempt',
                    ]],
                ],
                'usage' => ['total_tokens' => 4],
                'stop_reason' => 'stop',
                'error' => null,
            ],
            [
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'retry-attempt',
                    ]],
                ],
                'usage' => ['total_tokens' => 4],
                'stop_reason' => 'stop',
                'error' => null,
            ],
        ]);

        $message = new ExecuteLlmStep(
            runId: 'run-failure-worker-1',
            turnNo: 1,
            stepId: 'turn-1-llm-1',
            attempt: 1,
            idempotencyKey: 'llm-failure-worker-1',
            contextRef: 'hot:run:run-failure-worker-1',
            toolsRef: 'toolset:run:run-failure-worker-1:turn:1',
        );

        $failingWorker = new ExecuteLlmStepWorker(
            platform: $platform,
            commandBus: new FailingOnceMessageBus(new TransportException('simulated dispatch crash')),
        );

        try {
            $failingWorker($message);
            self::fail('Expected dispatch crash to bubble as RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Failed to dispatch LLM result to command bus.', $exception->getMessage());
        }

        $collectingBus = new DrillCollectingMessageBus();
        $retryWorker = new ExecuteLlmStepWorker($platform, $collectingBus);
        $retryWorker($message);

        self::assertCount(1, $collectingBus->messages);
        self::assertInstanceOf(LlmStepResult::class, $collectingBus->messages[0]);

        /** @var LlmStepResult $result */
        $result = $collectingBus->messages[0];
        self::assertSame('retry-attempt', $result->assistantMessage['content'][0]['text']);
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
        );

        try {
            $failingWorker($message);
            self::fail('Expected dispatch crash to bubble as RuntimeException.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Failed to dispatch tool result to command bus.', $exception->getMessage());
        }

        $collectingBus = new DrillCollectingMessageBus();
        $retryWorker = new ExecuteToolCallWorker($toolExecutor, $collectingBus);
        $retryWorker($message);

        self::assertCount(1, $collectingBus->messages);
        self::assertInstanceOf(ToolCallResult::class, $collectingBus->messages[0]);

        /** @var ToolCallResult $result */
        $result = $collectingBus->messages[0];
        self::assertSame('web_search', $result->result['tool_name']);
        self::assertFalse($result->isError);
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

final class DrillCollectingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
