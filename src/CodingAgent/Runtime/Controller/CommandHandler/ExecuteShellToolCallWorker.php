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

/**
 * Executes a shell tool call on the tool consumer.
 *
 * Canonical tool_execution_start is committed by ApplyShellCommandHandler before
 * this effect is dispatched. The worker returns ToolCallResult to the execution
 * middleware, which persists it and notifies run_control for canonical completion.
 */
#[AsMessageHandler(bus: 'agent.execution.bus')]
final readonly class ExecuteShellToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private ?LoggerInterface $logger = null,
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
            return $this->execute($message);
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

        $this->logger?->info('shell.tool_result_dispatched', [
            'run_id' => $message->runId(),
            'component' => 'tool.shell',
            'event_type' => 'shell.tool_result_dispatched',
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
