<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Shared completion dispatch for deferred batches: generic deferred-status check,
 * CompleteDeferredToolCall dispatch, and durable terminal-enqueued marker.
 */
final readonly class DeferredSubagentBatchCompletionDispatcher
{
    public function __construct(
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private DeferredSubagentBatchRepository $batchRepository,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{error: array<string, mixed>, details: array<string, mixed>}|null $errorEnvelope
     */
    public function dispatchCompletion(
        string $lifecycleId,
        string $parentRunId,
        string $parentToolCallId,
        int $expectedProjectionVersion,
        string $presentation,
        bool $isError,
        ?array $errorEnvelope,
    ): void {
        $deferredStatus = $this->deferredToolCompletionRepository->status($lifecycleId);
        if (null === $deferredStatus) {
            $this->logger->info('deferred_subagent_batch.completion_waiting_for_registration', [
                'batch_lifecycle_id' => $lifecycleId,
                'parent_run_id' => $parentRunId,
                'tool_call_id' => $parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.completion_waiting_for_registration',
            ]);

            return;
        }

        if ('completed' === $deferredStatus) {
            $this->markTerminalEnqueued($lifecycleId, $parentRunId, $parentToolCallId, $expectedProjectionVersion);

            return;
        }

        try {
            $this->commandBus->dispatch(new CompleteDeferredToolCall(
                deferredId: $lifecycleId,
                content: [['type' => 'text', 'text' => $presentation]],
                details: $errorEnvelope['details'] ?? null,
                isError: $isError,
                error: $errorEnvelope['error'] ?? null,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch deferred subagent batch completion.', previous: $exception);
        }

        $this->markTerminalEnqueued($lifecycleId, $parentRunId, $parentToolCallId, $expectedProjectionVersion);
    }

    private function markTerminalEnqueued(
        string $lifecycleId,
        string $parentRunId,
        string $parentToolCallId,
        int $expectedProjectionVersion,
    ): void {
        try {
            $this->batchRepository->markTerminalCompletionEnqueued(
                batchLifecycleId: $lifecycleId,
                enqueuedAt: new \DateTimeImmutable(),
                expectedProjectionVersion: $expectedProjectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_subagent_batch.terminal_completion_marker_conflict', [
                'batch_lifecycle_id' => $lifecycleId,
                'parent_run_id' => $parentRunId,
                'tool_call_id' => $parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.terminal_completion_marker_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
