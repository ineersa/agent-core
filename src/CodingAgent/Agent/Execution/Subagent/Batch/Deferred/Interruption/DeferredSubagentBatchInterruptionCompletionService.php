<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchCompletionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchSupervisionResultDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchChildOutcomeFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchCompletionDispatcher;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\Subagent\SubagentParallelAggregateResultFormatter;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;

/**
 * Interruption completion: forced progress, artifact outcomes, and deferred dispatch.
 */
final readonly class DeferredSubagentBatchInterruptionCompletionService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private SubagentParallelAggregateResultFormatter $parallelFormatter,
        private SubagentChildRunHandoffRenderer $handoffRenderer,
        private DeferredSubagentBatchProgressDeliveryService $progressDelivery,
        private DeferredSubagentBatchCompletionDispatcher $completionDispatcher,
        private DeferredSubagentBatchChildOutcomeFactory $outcomeFactory,
    ) {
    }

    public function completeFromInterruption(DeferredSubagentBatchProjectionDTO $batch): void
    {
        if (null !== $batch->terminalCompletionEnqueuedAt) {
            return;
        }

        $kind = $batch->interruptionKind ?? throw new \RuntimeException('Interruption completion requires persisted interruption kind.');

        if (ChildRunBatchExecutionModeEnum::Single === $batch->executionMode) {
            $this->completeSingleFromInterruption($batch, $kind);

            return;
        }

        $this->completeParallelFromInterruption($batch, $kind);
    }

    private function completeSingleFromInterruption(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentInterruptionKindEnum $kind,
    ): void {
        if (null === $batch->interruptionProgressEnqueuedAt) {
            $appended = $this->progressDelivery->emitForcedInterruptionProgress($batch, $kind);

            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }

            if ($appended) {
                try {
                    $this->batchRepository->markInterruptionProgressEnqueued(
                        batchLifecycleId: $batch->lifecycleId,
                        enqueuedAt: new \DateTimeImmutable(),
                        expectedProjectionVersion: $batch->projectionVersion,
                    );
                } catch (OptimisticLockException $exception) {
                    $resolved = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
                    if (null === $resolved || null !== $resolved->terminalCompletionEnqueuedAt) {
                        return;
                    }
                    if (null === $resolved->interruptionProgressEnqueuedAt) {
                        throw $exception;
                    }
                    $batch = $resolved;
                }
            }

            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }
        }

        $child = $this->requireSingleChild($batch);
        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $timeoutSecs = DeferredSubagentInterruptionKindEnum::Timeout === $kind
            ? $this->resolveTimeoutSeconds($batch)
            : 0;
        $artifactOutcome = $this->buildSingleInterruptionArtifactOutcome($identity, $kind, $timeoutSecs);
        $this->lifecycleListener->finalizeTerminalOutcome(
            ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
        );

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            $presentation = $this->handoffRenderer->formatTimeoutResult(
                $identity->displayName,
                $timeoutSecs,
                $identity->taskSummary,
                $identity->artifactId,
            );
            $this->completionDispatcher->dispatchCompletion(
                lifecycleId: $batch->lifecycleId,
                parentRunId: $batch->parentRunId,
                parentToolCallId: $batch->parentToolCallId,
                expectedProjectionVersion: $batch->projectionVersion,
                presentation: $presentation,
                isError: false,
                errorEnvelope: null,
            );

            return;
        }

        $presentation = $this->handoffRenderer->formatParentCancelledSingleMessage($identity->displayName, $identity->artifactId);
        $errorEnvelope = $this->buildErrorEnvelope($presentation, true);
        $this->completionDispatcher->dispatchCompletion(
            lifecycleId: $batch->lifecycleId,
            parentRunId: $batch->parentRunId,
            parentToolCallId: $batch->parentToolCallId,
            expectedProjectionVersion: $batch->projectionVersion,
            presentation: $presentation,
            isError: true,
            errorEnvelope: $errorEnvelope,
        );
    }

    private function completeParallelFromInterruption(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentInterruptionKindEnum $kind,
    ): void {
        if (DeferredSubagentInterruptionKindEnum::ParentCancelled === $kind
            && null === $batch->interruptionProgressEnqueuedAt) {
            $appended = $this->progressDelivery->emitForcedInterruptionProgress($batch, $kind);

            $batch = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
            if (null === $batch || null !== $batch->terminalCompletionEnqueuedAt) {
                return;
            }

            if (!$appended) {
                return;
            }

            try {
                $this->batchRepository->markInterruptionProgressEnqueued(
                    batchLifecycleId: $batch->lifecycleId,
                    enqueuedAt: new \DateTimeImmutable(),
                    expectedProjectionVersion: $batch->projectionVersion,
                );
            } catch (OptimisticLockException $exception) {
                $resolved = $this->batchRepository->findByLifecycleId($batch->lifecycleId);
                if (null === $resolved || null !== $resolved->terminalCompletionEnqueuedAt) {
                    return;
                }
                if (null !== $resolved->interruptionProgressEnqueuedAt) {
                    $batch = $resolved;
                } else {
                    throw $exception;
                }
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
            $artifactOutcome = $this->buildParallelInterruptionArtifactOutcome($identity, $child, $kind, $timeoutSecs);
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

    private function buildSingleInterruptionArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredSubagentInterruptionKindEnum $kind,
        int $timeoutSecs,
    ): ChildRunTerminalOutcomeDTO {
        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            return new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$timeoutSecs.'s.',
            );
        }

        return new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Cancelled by parent run.',
        );
    }

    private function buildParallelInterruptionArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredSubagentChildProjectionDTO $child,
        DeferredSubagentInterruptionKindEnum $kind,
        int $timeoutSecs,
    ): ChildRunTerminalOutcomeDTO {
        $cp = $child->childLifecycleProjection;
        if (null !== $cp && $cp->childStatus->isTerminal()) {
            return $this->outcomeFactory->buildNaturalArtifactOutcome($identity, $cp);
        }

        if (DeferredSubagentInterruptionKindEnum::Timeout === $kind) {
            return new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: \sprintf('Timed out after %ds.', $timeoutSecs),
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
            return \sprintf('Timed out after %ds.', $timeoutSecs);
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

    private function requireSingleChild(DeferredSubagentBatchProjectionDTO $batch): DeferredSubagentChildProjectionDTO
    {
        if (1 !== $batch->totalChildCount || 1 !== \count($batch->children)) {
            throw new \RuntimeException('Single batch interruption requires exactly one child row.');
        }

        return $batch->children[0];
    }
}
