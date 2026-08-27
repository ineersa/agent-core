<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
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
        $correlation = $this->deferredRepository->findByDeferredId($message->deferredId);
        if (null === $correlation) {
            $this->logger->warning('deferred_tool_completion.unknown_correlation', [
                'deferred_id' => $message->deferredId,
                'component' => 'tool',
                'event_type' => 'deferred_tool_completion.unknown_correlation',
            ]);

            throw new \RuntimeException(\sprintf('Unknown deferred tool completion id "%s".', $message->deferredId));
        }

        RunLogContext::enter([
            'run_id' => $correlation->runId,
            'session_id' => $correlation->runId,
            'component' => 'tool',
            'event_type' => 'deferred_tool_completion.started',
            'deferred_id' => $message->deferredId,
            'tool_call_id' => $correlation->toolCallId,
        ]);

        try {
            if ('completed' === $this->deferredRepository->status($message->deferredId)) {
                return;
            }

            // Dispatch first while status remains pending so Messenger retries can re-dispatch
            // after transport/handler failures. Mark completed only after dispatch succeeds.
            // If dispatch succeeds but markCompleted fails, a later retry may dispatch again.
            // ToolCallResultHandler and the durable tool-batch state admit the terminal result only once,
            // so a duplicate delivery produces no additional observable pipeline transition.
            $toolCallResult = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
                $correlation,
                $message->content,
                $message->details,
                $message->isError,
                $message->error,
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
