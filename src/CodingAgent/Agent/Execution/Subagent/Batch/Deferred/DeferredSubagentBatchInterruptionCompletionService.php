<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;

/**
 * Interruption completion: forced parent-cancel progress, artifact outcomes, and deferred dispatch.
 */
final readonly class DeferredSubagentBatchInterruptionCompletionService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private SubagentParallelAggregateResultFormatter $parallelFormatter,
        private DeferredSubagentBatchProgressDeliveryService $progressDelivery,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private DeferredSubagentBatchChildOutcomeFactory $outcomeFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function completeFromInterruption(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $kind = $batch->interruptionKind ?? throw new \RuntimeException('Interruption completion requires persisted interruption kind.');

        // Emit forced parent-cancel progress exactly once
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $kind
            && null === $batch->interruptionProgressEnqueuedAt) {
            $this->progressDelivery->emitForcedParentCancelProgress($batch);

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
                    'parent_run_id' => $batch->parentRunId,
                    'tool_call_id' => $batch->parentToolCallId,
                    'component' => 'agent.execution',
                    'event_type' => 'deferred_subagent_batch.interruption_progress_marker_conflict',
                    'exception_class' => $exception::class,
                ]);

                $resolved = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
                if (null === $resolved || null !== $resolved->terminalCompletionEnqueuedAt) {
                    return;
                }
                if (null === $resolved->interruptionProgressEnqueuedAt) {
                    throw $exception;
                }
                // Concurrent winner already enqueued progress marker — continue with fresh batch below
            }

            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }
        }

        $timeoutSecs = DeferredSubagentInterruptionKindEnum::Timeout === $kind
            ? $this->resolveTimeoutSeconds($batch)
            : 0;

        $items = [];
        foreach ($batch->children as $child) {
            $identity = $this->outcomeFactory->identityFromChild($batch, $child);
            $artifactOutcome = $this->buildInterruptionArtifactOutcome($identity, $child, $kind, $timeoutSecs);
            $this->lifecycleListener->finalizeTerminalOutcome(
                ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
            );

            $items[] = new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: true,
                artifactStatus: $artifactOutcome->status,
                message: $this->interruptionChildMessage($artifactOutcome, $kind, $timeoutSecs),
            );
        }

        $result = new ChildRunBatchSupervisionResultDTO(
            parentRunId: $batch->parentRunId,
            items: $items,
            completionKind: ChildRunBatchCompletionKindEnum::PartialFailure,
        );

        $report = $this->parallelFormatter->formatReport($result);

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            $message = 'Parallel subagents timed out after '.$timeoutSecs.' seconds.'."\n\n".$report;
            $errorEnvelope = $this->buildErrorEnvelope($message, false);
        } else {
            $message = 'Parallel subagent tool cancelled by parent run.'."\n\n".$report;
            $errorEnvelope = $this->buildErrorEnvelope($message, true);
        }

        $this->completionDispatcher->dispatchCompletion(
            lifecycleId: $batch->lifecycleId,
            parentRunId: $batch->parentRunId,
            parentToolCallId: $batch->parentToolCallId,
            expectedProjectionVersion: $batch->projectionVersion,
            presentation: $message,
            isError: true,
            errorEnvelope: $errorEnvelope,
        );
    }

    /**
     * @return array{error: array<string, mixed>, details: array<string, mixed>}
     */
    private function buildErrorEnvelope(string $message, bool $cancelled): array
    {
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

        if ($cancelled) {
            $error['cancelled'] = true;
            $details['cancelled'] = true;
        }

        return ['error' => $error, 'details' => $details];
    }

    private function buildInterruptionArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredSubagentChildProjectionDTO $child,
        DeferredSubagentInterruptionKindEnum $kind,
        int $timeoutSecs,
    ): ChildRunTerminalOutcomeDTO {
        // Preserve already-terminal children naturally
        $cp = $child->childLifecycleProjection;
        if (null !== $cp && $cp->childStatus->isTerminal()) {
            return $this->outcomeFactory->buildNaturalArtifactOutcome($identity, $cp);
        }

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            return new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: \sprintf('Timed out after %d%s.', $timeoutSecs, 1 === $timeoutSecs ? ' second' : ' seconds'),
            );
        }

        return new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Cancelled by parent run.',
        );
    }

    private function interruptionChildMessage(
        ChildRunTerminalOutcomeDTO $outcome,
        DeferredSubagentInterruptionKindEnum $kind,
        int $timeoutSecs,
    ): string {
        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            return \sprintf('Timed out after %d%s.', $timeoutSecs, 1 === $timeoutSecs ? ' second' : ' seconds');
        }

        return $outcome->summary ?? '';
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
}
