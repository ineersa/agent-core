<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Executes a shell tool call on the tool consumer.
 *
 * Canonical tool_execution_start is committed by ApplyShellCommandHandler before
 * this effect is dispatched. The worker posts ToolCallResult to the
 * command bus for canonical completion.
 */
#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteShellToolCallWorker
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private ToolExecutorInterface $toolExecutor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExecuteShellToolCall $message): ToolCallResult
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
            $result = $this->execute($message);
            try {
                $this->commandBus->dispatch($result);
            } catch (\Throwable $exception) {
                $this->logger->warning('runtime.result_send_failed', [
                    'run_id' => $message->runId(),
                    'session_id' => $message->runId(),
                    'component' => 'shell_worker',
                    'event_type' => 'runtime.result_send_failed',
                    'exception_class' => $exception::class,
                ]);
            }

            return $result;
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteShellToolCall $message): ToolCallResult
    {
        $arguments = ['command' => $message->commandText];
        $result = $this->toolExecutor->execute(new ToolCall(
            toolCallId: $message->toolCallId,
            toolName: 'bash',
            arguments: $arguments,
            orderIndex: 0,
            runId: $message->runId(),
        ));

        $this->logger->info('shell.tool_result_ready', [
            'run_id' => $message->runId(),
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_result_ready',
            'tool_call_id' => $message->toolCallId,
            'is_error' => $result->isError,
        ]);

        return (new ToolCallResult(
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
        ))->finalized();
    }
}
