<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Contract\Tool\PlatformInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExecutionWorkerTest extends TestCase
{
    public function testLlmWorkerConvertsPlatformErrorsIntoStructuredResultMessage(): void
    {
        $platform = new class implements PlatformInterface {
            public function invoke(string $model, array $input, array $options = []): array
            {
                unset($model, $input, $options);

                throw new \RuntimeException('Provider unavailable.');
            }
        };

        $commandBus = new CollectingMessageBus();
        $worker = new ExecuteLlmStepWorker($platform, $commandBus);

        $worker(new ExecuteLlmStep(
            runId: 'run-worker-1',
            turnNo: 4,
            stepId: 'turn-4-llm-1',
            attempt: 2,
            idempotencyKey: 'llm-idemp-1',
            contextRef: 'hot:run:run-worker-1',
            toolsRef: 'toolset:run:run-worker-1:turn:4',
        ));

        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(LlmStepResult::class, $commandBus->messages[0]);

        /** @var LlmStepResult $result */
        $result = $commandBus->messages[0];

        self::assertSame('run-worker-1', $result->runId());
        self::assertSame('turn-4-llm-1', $result->stepId());
        self::assertNotNull($result->error);
        self::assertSame('Provider unavailable.', $result->error['message']);
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

        $commandBus = new CollectingMessageBus();
        $worker = new ExecuteToolCallWorker($toolExecutor, $commandBus);

        $worker(new ExecuteToolCall(
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

        self::assertCount(1, $commandBus->messages);
        self::assertInstanceOf(ToolCallResult::class, $commandBus->messages[0]);

        /** @var ToolCallResult $result */
        $result = $commandBus->messages[0];

        self::assertSame('call-1', $result->toolCallId);
        self::assertFalse($result->isError);
        self::assertSame('web_search', $result->result['tool_name']);
    }
}

final class CollectingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
