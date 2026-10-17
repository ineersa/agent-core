<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedNativeHandoffGeneration;
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
        private ToolLaunchInputStoreInterface $launchInputStore,
    ) {
    }

    /**
     * @param array{error: array<string, mixed>, details: array<string, mixed>}|null $errorEnvelope
     * @param list<RetainedNativeHandoffGeneration>                                  $nativeHandoffs
     */
    public function dispatchCompletion(
        string $lifecycleId,
        string $parentRunId,
        string $parentToolCallId,
        int $expectedProjectionVersion,
        string $presentation,
        bool $isError,
        ?array $errorEnvelope,
        array $nativeHandoffs = [],
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

        // Registration makes execution redelivery independent of launch input.
        // Terminal projections and artifact outcomes rebuild the handoff.
        try {
            $this->launchInputStore->delete($parentRunId, $parentToolCallId);
        } catch (\Throwable $exception) {
            $this->logger->warning('deferred_subagent_batch.launch_input_cleanup_failed', [
                'batch_lifecycle_id' => $lifecycleId,
                'run_id' => $parentRunId,
                'session_id' => $parentRunId,
                'tool_call_id' => $parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.launch_input_cleanup_failed',
                'exception_class' => $exception::class,
            ]);
        }

        if ('completed' === $deferredStatus) {
            $this->markTerminalEnqueued($lifecycleId, $parentRunId, $parentToolCallId, $expectedProjectionVersion);

            return;
        }

        try {
            $details = $errorEnvelope['details'] ?? [];
            if (!\is_array($details)) {
                $details = [];
            }
            if ([] !== $nativeHandoffs) {
                $details['native_artifact_handoffs'] = array_map(
                    static fn (RetainedNativeHandoffGeneration $generation): array => [
                        'parent_run_id' => $generation->parentRunId,
                        'artifact_id' => $generation->artifactId,
                        'handoff_id' => $generation->handoffId,
                        'bytes' => $generation->bytes,
                        'sha256' => $generation->sha256,
                        'media_type' => $generation->mediaType,
                    ],
                    $nativeHandoffs,
                );
                // Single-child convenience fields for the authoritative first generation.
                $first = $nativeHandoffs[0];
                $details['artifact_id'] = $first->artifactId;
                $details['handoff_id'] = $first->handoffId;
                $details['handoff_bytes'] = $first->bytes;
                $details['handoff_sha256'] = $first->sha256;
                $details['handoff_media_type'] = $first->mediaType;
                $details['parent_run_id'] = $first->parentRunId;
            }
            $this->commandBus->dispatch(new CompleteDeferredToolCall(
                deferredId: $lifecycleId,
                content: [['type' => 'text', 'text' => $presentation]],
                details: [] === $details ? null : $details,
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
