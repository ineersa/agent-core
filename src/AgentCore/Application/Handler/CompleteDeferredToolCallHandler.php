<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Resolves a deferred correlation and publishes the ordinary ToolCallResult.
 *
 * Transport send is not owner acceptance. Deferred status becomes completed only
 * when ToolCallResultHandler commits the accepted terminal lifecycle projection.
 */
final readonly class CompleteDeferredToolCallHandler
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferredRepository,
        private MessageBusInterface $commandBus,
        private RunLockManager $locks,
        private \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface $batches,
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

            $toolCallResult = ToolCallResultFactory::fromDeferredCorrelationAndCompletion(
                $correlation,
                $message->content,
                $message->details,
                $message->isError,
                $message->error,
            );
            // Keep the pending correlation until owner acceptance. Successful
            // transport send alone must not mark completed. A transport loss
            // needs explicit repair, not another publication queue.
            $this->locks->afterRelease($correlation->runId, function () use ($message, $correlation, $toolCallResult): void {
                try {
                    $this->commandBus->dispatch($toolCallResult);
                    // Synchronous delivery may already have accepted the terminal
                    // outcome and completed the deferred row. Best-effort batch
                    // cleanup remains gated by unresolved pending status.
                    $this->batches->delete($correlation->runId, $correlation->turnNo, $correlation->stepId);
                } catch (\Throwable $exception) {
                    $this->logger->warning('deferred_tool_completion.delivery_failed', [
                        'run_id' => $correlation->runId,
                        'session_id' => $correlation->runId,
                        'component' => 'deferred_tool_completion',
                        'event_type' => 'deferred_tool_completion.delivery_failed',
                        'deferred_id' => $message->deferredId,
                        'exception_class' => $exception::class,
                    ]);
                }
            });
        } finally {
            RunLogContext::leave();
        }
    }
}
