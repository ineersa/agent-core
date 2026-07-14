<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Natural all-terminal batch completion from durable child projections (Piece 4B).
 */
final readonly class DeferredSubagentBatchTerminalCompletionService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private SubagentParallelAggregateResultFormatter $parallelFormatter,
        private DeferredSubagentBatchProgressDeliveryService $progressDelivery,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    public function completeIfAllTerminal(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $items = [];
        foreach ($batch->children as $child) {
            $projection = $child->childLifecycleProjection;
            if (null === $projection || !$projection->childStatus->isTerminal()) {
                return;
            }

            $identity = $this->identityFromChild($batch, $child);
            $artifactOutcome = $this->buildNaturalArtifactOutcome($identity, $projection);
            $this->lifecycleListener->finalizeTerminalOutcome(
                ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
            );

            $items[] = new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: true,
                artifactStatus: $artifactOutcome->status,
                message: $this->messageFromProjection($projection, $artifactOutcome),
            );
        }

        if (\count($items) !== $batch->totalChildCount) {
            return;
        }

        $allCompleted = true;
        foreach ($items as $item) {
            if (AgentArtifactStatusEnum::Completed !== $item->artifactStatus) {
                $allCompleted = false;
                break;
            }
        }

        $completionKind = $allCompleted
            ? ChildRunBatchCompletionKindEnum::AllSucceeded
            : ChildRunBatchCompletionKindEnum::PartialFailure;

        $result = new ChildRunBatchSupervisionResultDTO(
            parentRunId: $batch->parentRunId,
            items: $items,
            completionKind: $completionKind,
        );

        if ($allCompleted) {
            $presentation = $this->parallelFormatter->formatSuccess($result);
            $this->finalizeAndDispatch($batch, $presentation, false, null);
        } else {
            $report = $this->parallelFormatter->formatReport($result);
            $message = 'Parallel subagent execution failed for one or more children.'."\n\n".$report;
            $error = [
                'type' => ToolCallException::class,
                'message' => $message,
                'retryable' => false,
                'hint' => null,
            ];
            $details = [
                'error_type' => ToolCallException::class,
                'retryable' => false,
                'hint' => null,
            ];
            $this->finalizeAndDispatch($batch, $message, true, ['error' => $error, 'details' => $details]);
        }
    }

    /**
     * Completes a batch from a durable interruption (timeout or parent cancel).
     */
    public function completeFromInterruption(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $kind = $batch->interruptionKind ?? throw new \RuntimeException('Interruption completion requires persisted interruption kind.');

        // Parent cancel: emit forced terminal parallel progress with aggregate 'cancelled' exactly once
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $kind
            && null === $batch->interruptionProgressEnqueuedAt) {
            try {
                $this->progressDelivery->deliverIfNeeded($batch);
            } catch (\Throwable $exception) {
                $this->logger->warning('deferred_subagent_batch.interruption_progress_forced_failed', [
                    'batch_lifecycle_id' => $batch->lifecycleId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.interruption_progress_forced_failed',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }

            // Re-read after deliverIfNeeded because it bumps the projection version
            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }

            try {
                $this->batchRepository->markInterruptionProgressEnqueued(
                    batchLifecycleId: $batch->lifecycleId,
                    enqueuedAt: new \DateTimeImmutable(),
                    expectedProjectionVersion: $batch->projectionVersion,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_subagent_batch.interruption_progress_marker_conflict', [
                    'batch_lifecycle_id' => $batch->lifecycleId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.interruption_progress_marker_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }

            // Re-read after marker so finalizeAndDispatch uses the current version
            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }
        }

        // Build artifact outcomes for all children
        $items = [];
        foreach ($batch->children as $child) {
            $identity = $this->identityFromChild($batch, $child);
            $artifactOutcome = $this->buildInterruptionArtifactOutcome($identity, $child, $kind);
            $this->lifecycleListener->finalizeTerminalOutcome(
                ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
            );

            $items[] = new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: true,
                artifactStatus: $artifactOutcome->status,
                message: $artifactOutcome->failureReason ?? $artifactOutcome->summary ?? '',
            );
        }

        $result = new ChildRunBatchSupervisionResultDTO(
            parentRunId: $batch->parentRunId,
            items: $items,
            completionKind: ChildRunBatchCompletionKindEnum::PartialFailure,
        );

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            $timeoutSecs = $this->resolveTimeoutSeconds($batch);
            $report = $this->parallelFormatter->formatReport($result);
            $message = 'Parallel subagents timed out after '.$timeoutSecs.' seconds.'."\n\n".$report;
            $error = [
                'type' => ToolCallException::class,
                'message' => $message,
                'retryable' => false,
                'hint' => null,
            ];
            $details = [
                'error_type' => ToolCallException::class,
                'retryable' => false,
                'hint' => null,
            ];
            $this->finalizeAndDispatch($batch, $message, true, ['error' => $error, 'details' => $details]);
        } else {
            $report = $this->parallelFormatter->formatReport($result);
            $message = 'Parallel subagent tool cancelled by parent run.'."\n\n".$report;
            $error = [
                'type' => ToolCallException::class,
                'message' => $message,
                'retryable' => false,
                'hint' => null,
                'cancelled' => true,
            ];
            $details = [
                'error_type' => ToolCallException::class,
                'retryable' => false,
                'hint' => null,
                'cancelled' => true,
            ];
            $this->finalizeAndDispatch($batch, $message, true, ['error' => $error, 'details' => $details]);
        }
    }

    /**
     * @param array{error: array<string, mixed>, details: array<string, mixed>}|null $errorEnvelope
     */
    private function finalizeAndDispatch(
        DeferredSubagentBatchProjectionDTO $batch,
        string $presentation,
        bool $isError,
        ?array $errorEnvelope,
    ): void {
        $deferredStatus = $this->deferredToolCompletionRepository->status($batch->lifecycleId);
        if (null === $deferredStatus) {
            $this->logger->info('deferred_subagent_batch.completion_waiting_for_registration', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.completion_waiting_for_registration',
            ]);

            return;
        }

        if ('completed' === $deferredStatus) {
            try {
                $this->batchRepository->markTerminalCompletionEnqueued(
                    batchLifecycleId: $batch->lifecycleId,
                    enqueuedAt: new \DateTimeImmutable(),
                    expectedProjectionVersion: $batch->projectionVersion,
                );
            } catch (OptimisticLockException $exception) {
                $this->logger->warning('deferred_subagent_batch.terminal_completion_marker_conflict', [
                    'batch_lifecycle_id' => $batch->lifecycleId,
                    'parent_run_id' => $batch->parentRunId,
                    'tool_call_id' => $batch->parentToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.terminal_completion_marker_conflict',
                    'exception_class' => $exception::class,
                ]);

                throw $exception;
            }

            return;
        }

        try {
            $this->commandBus->dispatch(new CompleteDeferredToolCall(
                deferredId: $batch->lifecycleId,
                content: [['type' => 'text', 'text' => $presentation]],
                details: $errorEnvelope['details'] ?? null,
                isError: $isError,
                error: $errorEnvelope['error'] ?? null,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch deferred subagent batch completion.', previous: $exception);
        }

        try {
            $this->batchRepository->markTerminalCompletionEnqueued(
                batchLifecycleId: $batch->lifecycleId,
                enqueuedAt: new \DateTimeImmutable(),
                expectedProjectionVersion: $batch->projectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_subagent_batch.terminal_completion_marker_conflict', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.terminal_completion_marker_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function identityFromChild(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): ChildRunIdentityDTO {
        return new ChildRunIdentityDTO(
            parentRunId: $batch->parentRunId,
            childRunId: $child->childRunId,
            artifactId: $child->artifactId,
            displayName: $child->agentName,
            taskSummary: $child->task,
            definitionModel: $child->definitionModel,
            artifactKind: AgentArtifactKindEnum::Subagent,
            batchIndex: $child->batchIndex,
        );
    }

    private function buildNaturalArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredChildRunLifecycleProjectionDTO $projection,
    ): ChildRunTerminalOutcomeDTO {
        return match ($projection->childStatus) {
            RunStatus::Completed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Completed,
                summary: $this->completedSummaryText($projection),
            ),
            RunStatus::Failed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: $projection->errorMessage ?? 'Run failed without error message.',
                summary: $projection->errorMessage ?? 'Run failed without error message.',
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Cancelled,
                summary: 'Child run was cancelled.',
            ),
            default => throw new \RuntimeException('Deferred subagent batch delivery reached terminal completion with non-terminal child status.'),
        };
    }

    private function messageFromProjection(
        DeferredChildRunLifecycleProjectionDTO $projection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
    ): string {
        if (RunStatus::Failed === $projection->childStatus) {
            return $artifactOutcome->failureReason ?? 'Run failed without error message.';
        }

        if (RunStatus::Cancelled === $projection->childStatus || RunStatus::Cancelling === $projection->childStatus) {
            return 'Child run was cancelled.';
        }

        return $this->completedSummaryText($projection);
    }

    private function buildInterruptionArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredSubagentChildProjectionDTO $child,
        DeferredSubagentInterruptionKindEnum $kind,
    ): ChildRunTerminalOutcomeDTO {
        // Preserve already-terminal children naturally
        $cp = $child->childLifecycleProjection;
        if (null !== $cp && $cp->childStatus->isTerminal()) {
            return $this->buildNaturalArtifactOutcome($identity, $cp);
        }

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            return new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Child run timed out.',
            );
        }

        return new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Cancelled by parent run.',
        );
    }

    private function resolveTimeoutSeconds(DeferredSubagentBatchProjectionDTO $batch): int
    {
        if (null !== $batch->deadlineAt) {
            $anchor = $batch->startedAt ?? $batch->createdAt;
            $seconds = $batch->deadlineAt->getTimestamp() - $anchor->getTimestamp();

            return max(1, $seconds);
        }

        return 1;
    }

    private function completedSummaryText(DeferredChildRunLifecycleProjectionDTO $projection): string
    {
        $text = trim($projection->assistantResultText ?? '');
        if ('' !== $text) {
            return $text;
        }

        return 'Completed with status completed.';
    }
}
