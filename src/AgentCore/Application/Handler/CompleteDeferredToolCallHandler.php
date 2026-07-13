<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CompleteDeferredToolCallHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferredRepository,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function __invoke(CompleteDeferredToolCall $message): void
    {
        RunLogContext::enter([
            'run_id' => $message->runId(),
            'session_id' => $message->runId(),
            'component' => 'tool',
            'event_type' => 'deferred_tool_completion.started',
            'deferred_id' => $message->deferredId,
            'tool_call_id' => $message->toolCallId,
        ]);

        try {
            $correlation = $this->deferredRepository->findByDeferredId($message->deferredId);
            if (null === $correlation) {
                $this->logger->warning('deferred_tool_completion.unknown_correlation', [
                    'run_id' => $message->runId(),
                    'deferred_id' => $message->deferredId,
                    'tool_call_id' => $message->toolCallId,
                    'component' => 'tool',
                    'event_type' => 'deferred_tool_completion.unknown_correlation',
                ]);

                throw new \RuntimeException(\sprintf('Unknown deferred tool completion id "%s" for run "%s".', $message->deferredId, $message->runId()));
            }

            if ('completed' === $this->deferredRepository->status($message->deferredId)) {
                return;
            }

            if (!$this->deferredRepository->tryBeginCompletion($message->deferredId)) {
                $status = $this->deferredRepository->status($message->deferredId);
                if ('completed' === $status || 'completing' === $status) {
                    return;
                }

                throw new \RuntimeException(\sprintf('Deferred tool completion "%s" cannot begin (status=%s).', $message->deferredId, $status ?? 'missing'));
            }

            $toolIdempotencyKey = $correlation->toolIdempotencyKey ?? $message->toolIdempotencyKey;

            $toolCallResult = new ToolCallResult(
                runId: $correlation->runId,
                turnNo: $correlation->turnNo,
                stepId: $correlation->stepId,
                attempt: $correlation->attempt,
                idempotencyKey: $correlation->idempotencyKey,
                toolCallId: $correlation->toolCallId,
                orderIndex: $correlation->orderIndex,
                result: [
                    'tool_name' => $message->toolName,
                    'content' => $message->content,
                    'details' => $message->details,
                    'tool_idempotency_key' => $toolIdempotencyKey,
                    'mode' => $correlation->mode,
                    'arguments' => $correlation->arguments,
                ],
                isError: $message->isError,
                error: $message->error,
            );

            try {
                $this->commandBus->dispatch($toolCallResult);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch deferred tool ToolCallResult.', previous: $exception);
            }

            $this->deferredRepository->markCompleted($message->deferredId);
        } finally {
            RunLogContext::leave();
        }
    }
}
