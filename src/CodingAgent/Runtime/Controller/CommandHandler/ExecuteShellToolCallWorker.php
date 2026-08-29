<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Executes a shell tool call on the tool consumer.
 *
 * The worker records only the start side event needed while the command is in
 * flight. Its durable result is sent to run_control, which is the sole owner
 * of completion events, operational projection, and standalone termination.
 */
#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteShellToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private EventStoreInterface $eventStore,
        private MessageBusInterface $commandBus,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(ExecuteShellToolCall $message): void
    {
        RunLogContext::enter([
            'run_id' => $message->runId(),
            'session_id' => $message->runId(),
            'component' => 'tool',
            'queue' => 'agent.execution.bus',
            'worker' => 'shell_tool',
            'tool_name' => 'bash',
        ]);

        try {
            $this->execute($message);
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteShellToolCall $message): void
    {
        if ('' === $message->commandText) {
            return;
        }

        $arguments = ['command' => $message->commandText];
        $this->eventStore->append(new RunEvent(
            runId: $message->runId(),
            seq: 0,
            turnNo: $message->turnNo(),
            type: RunEventTypeEnum::ToolExecutionStart->value,
            payload: [
                'tool_call_id' => $message->toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
                'attempt' => $message->attempt(),
                'arguments' => $arguments,
            ],
        ));

        $result = $this->toolExecutor->execute(new ToolCall(
            toolCallId: $message->toolCallId,
            toolName: 'bash',
            arguments: $arguments,
            orderIndex: 0,
            runId: $message->runId(),
        ));

        try {
            $this->commandBus->dispatch(new ToolCallResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                toolCallId: $message->toolCallId,
                orderIndex: 0,
                result: [
                    'tool_name' => $result->toolName,
                    'content' => $result->content,
                    'details' => $result->details,
                    'arguments' => $arguments,
                    'standalone' => $message->standalone,
                ],
                isError: $result->isError,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch shell result to run_control.', previous: $exception);
        }

        $this->logger?->info('shell.tool_result_dispatched', [
            'run_id' => $message->runId(),
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_result_dispatched',
            'tool_call_id' => $message->toolCallId,
            'is_error' => $result->isError,
        ]);
    }
}
