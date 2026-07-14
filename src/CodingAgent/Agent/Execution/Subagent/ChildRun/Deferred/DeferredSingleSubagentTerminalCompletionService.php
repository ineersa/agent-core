<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalFinalizationRequestDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunTerminalOutcomeDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchLifecycleListenerInterface;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Result\SubagentChildRunHandoffRenderer;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Terminal parent progress, artifact finalization, and deferred tool completion for single-child deferred runs.
 */
final readonly class DeferredSingleSubagentTerminalCompletionService
{
    public function __construct(
        private DeferredSingleSubagentLaunchRepository $launchRepository,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private SubagentProgressEventAppender $progressEventAppender,
        private SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private ChildRunBatchLifecycleListenerInterface $lifecycleListener,
        private SubagentChildRunHandoffRenderer $handoffRenderer,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function deliverProgressIfNeeded(
        DeferredSingleSubagentProjectionDTO $projection,
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
        int $expectedProjectionVersion,
        ?DeferredSingleSubagentInterruptionKindEnum $interruptionKind = null,
        bool $forceInterruptionTerminalProgress = false,
    ): void {
        if (!$forceInterruptionTerminalProgress && $projection->childEventCursor <= $projection->parentProgressCursor) {
            return;
        }

        $progressStatus = $this->resolveProgressStatus($childProjection, $interruptionKind);
        $elapsedMs = $this->elapsedMsSince($projection->startedAt);
        $enrichment = $this->childProgressSummaryBuilder->fromDeferredProjection($childProjection, $projection->artifactId);

        $isTerminalProgress = null !== $interruptionKind || $childProjection->childStatus->isTerminal();
        $payload = $isTerminalProgress
            ? $this->progressSnapshotBuilder->singleTerminalFromChildTurn(
                $progressStatus,
                $projection->agentName,
                $projection->artifactId,
                $projection->childRunId,
                $projection->task,
                $childProjection->childTurnNo,
                $elapsedMs,
                $enrichment,
            )
            : $this->progressSnapshotBuilder->singleRunningFromChildTurn(
                $projection->agentName,
                $projection->artifactId,
                $projection->childRunId,
                $projection->task,
                $childProjection->childTurnNo,
                $elapsedMs,
                $enrichment,
                $progressStatus,
            );

        try {
            $this->progressEventAppender->append(
                parentRunId: $projection->parentRunId,
                parentTurnNo: $projection->parentTurnNo,
                parentToolCallId: $projection->parentToolCallId,
                parentOrderIndex: $projection->parentOrderIndex,
                toolName: 'subagent',
                progress: $payload,
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('deferred_single_subagent.parent_progress_append_failed', [
                'lifecycle_id' => $projection->lifecycleId,
                'parent_run_id' => $projection->parentRunId,
                'child_run_id' => $projection->childRunId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.parent_progress_append_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $interruptionProgressMarker = $forceInterruptionTerminalProgress && null !== $interruptionKind
            ? new \DateTimeImmutable()
            : null;

        try {
            $this->launchRepository->markParentProgressDelivery(
                lifecycleId: $projection->lifecycleId,
                parentProgressCursor: $projection->childEventCursor,
                interruptionProgressEnqueuedAt: $interruptionProgressMarker,
                expectedProjectionVersion: $expectedProjectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_single_subagent.parent_progress_cursor_conflict', [
                'lifecycle_id' => $projection->lifecycleId,
                'parent_run_id' => $projection->parentRunId,
                'child_run_id' => $projection->childRunId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.parent_progress_cursor_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function deliverInterruptionTerminalProgressIfNeeded(
        DeferredSingleSubagentProjectionDTO $projection,
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
        int $expectedProjectionVersion,
        DeferredSingleSubagentInterruptionKindEnum $interruptionKind,
    ): void {
        if (null !== $projection->interruptionProgressEnqueuedAt) {
            return;
        }

        if (null === $projection->childLifecycleProjection) {
            return;
        }

        $this->deliverProgressIfNeeded(
            $projection,
            $childProjection,
            $expectedProjectionVersion,
            $interruptionKind,
            forceInterruptionTerminalProgress: true,
        );
    }

    public function completeFromChildProjection(
        DeferredSingleSubagentProjectionDTO $projection,
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
        int $expectedProjectionVersion,
    ): void {
        if (null !== $projection->interruptionKind) {
            $this->completeFromInterruption($projection, $expectedProjectionVersion);

            return;
        }

        $identity = $this->identityFromProjection($projection);
        $artifactOutcome = $this->buildNaturalArtifactOutcome($identity, $childProjection);
        $presentation = $this->buildNaturalPresentation($identity, $childProjection, $artifactOutcome);
        $this->finalizeAndDispatch($projection, $artifactOutcome, $presentation, false, null, $expectedProjectionVersion);
    }

    public function completeFromInterruption(
        DeferredSingleSubagentProjectionDTO $projection,
        int $expectedProjectionVersion,
    ): void {
        $kind = $projection->interruptionKind ?? throw new \RuntimeException('Interruption completion requires persisted interruption kind.');
        $identity = $this->identityFromProjection($projection);

        if (DeferredSingleSubagentInterruptionKindEnum::Timeout === $kind) {
            $timeoutSeconds = $this->resolveTimeoutSeconds($projection);
            $artifactOutcome = new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: 'Child run timed out.',
                summary: 'Timed out after '.$timeoutSeconds.'s.',
            );
            $presentation = $this->handoffRenderer->formatTimeoutResult(
                $identity->displayName,
                $timeoutSeconds,
                $identity->taskSummary,
                $identity->artifactId,
            );
            $this->finalizeAndDispatch($projection, $artifactOutcome, $presentation, false, null, $expectedProjectionVersion);

            return;
        }

        $artifactOutcome = new ChildRunTerminalOutcomeDTO(
            identity: $identity,
            status: AgentArtifactStatusEnum::Cancelled,
            summary: 'Cancelled by parent run.',
        );
        $presentation = $this->handoffRenderer->formatParentCancelledSingleMessage($identity->displayName, $identity->artifactId);
        $error = [
            'type' => ToolCallException::class,
            'message' => $presentation,
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
        $this->finalizeAndDispatch($projection, $artifactOutcome, $presentation, true, ['error' => $error, 'details' => $details], $expectedProjectionVersion);
    }

    /**
     * @param array{error: array<string, mixed>, details: array<string, mixed>}|null $errorEnvelope
     */
    private function finalizeAndDispatch(
        DeferredSingleSubagentProjectionDTO $projection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
        string $presentation,
        bool $isError,
        ?array $errorEnvelope,
        int $expectedProjectionVersion,
    ): void {
        $this->lifecycleListener->finalizeTerminalOutcome(
            ChildRunTerminalFinalizationRequestDTO::persistOnly($artifactOutcome),
        );

        if (null === $this->deferredToolCompletionRepository->findByDeferredId($projection->lifecycleId)) {
            $this->logger->info('deferred_single_subagent.completion_waiting_for_registration', [
                'lifecycle_id' => $projection->lifecycleId,
                'parent_run_id' => $projection->parentRunId,
                'tool_call_id' => $projection->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.completion_waiting_for_registration',
            ]);

            throw new \RuntimeException('Deferred single subagent completion is waiting for generic deferred tool registration.');
        }

        try {
            $this->commandBus->dispatch(new CompleteDeferredToolCall(
                deferredId: $projection->lifecycleId,
                content: [['type' => 'text', 'text' => $presentation]],
                details: $errorEnvelope['details'] ?? null,
                isError: $isError,
                error: $errorEnvelope['error'] ?? null,
            ));
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch deferred single subagent completion.', previous: $exception);
        }

        try {
            $this->launchRepository->markTerminalCompletionEnqueued(
                lifecycleId: $projection->lifecycleId,
                enqueuedAt: new \DateTimeImmutable(),
                expectedProjectionVersion: $expectedProjectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_single_subagent.terminal_completion_marker_conflict', [
                'lifecycle_id' => $projection->lifecycleId,
                'parent_run_id' => $projection->parentRunId,
                'child_run_id' => $projection->childRunId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_single_subagent.terminal_completion_marker_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }
    }

    private function identityFromProjection(DeferredSingleSubagentProjectionDTO $projection): ChildRunIdentityDTO
    {
        return new ChildRunIdentityDTO(
            parentRunId: $projection->parentRunId,
            childRunId: $projection->childRunId,
            artifactId: $projection->artifactId,
            displayName: $projection->agentName,
            taskSummary: $projection->task,
            definitionModel: $projection->definitionModel,
            artifactKind: AgentArtifactKindEnum::Subagent,
        );
    }

    private function buildNaturalArtifactOutcome(
        ChildRunIdentityDTO $identity,
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
    ): ChildRunTerminalOutcomeDTO {
        return match ($childProjection->childStatus) {
            RunStatus::Completed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Completed,
                summary: $this->completedSummaryText($childProjection),
            ),
            RunStatus::Failed => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Failed,
                failureReason: $childProjection->errorMessage ?? 'Run failed without error message.',
                summary: $childProjection->errorMessage ?? 'Run failed without error message.',
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => new ChildRunTerminalOutcomeDTO(
                identity: $identity,
                status: AgentArtifactStatusEnum::Cancelled,
                summary: 'Child run was cancelled.',
            ),
            default => throw new \RuntimeException('Deferred single subagent delivery reached terminal completion with non-terminal child status.'),
        };
    }

    private function buildNaturalPresentation(
        ChildRunIdentityDTO $identity,
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
        ChildRunTerminalOutcomeDTO $artifactOutcome,
    ): string {
        return match ($childProjection->childStatus) {
            RunStatus::Completed => $this->handoffRenderer->formatCompletedResult(
                $identity->displayName,
                $identity->artifactId,
                $this->completedSummaryText($childProjection),
            ),
            RunStatus::Failed => $this->handoffRenderer->formatFailedResult(
                $identity->displayName,
                $identity->artifactId,
                $artifactOutcome->failureReason ?? 'Run failed without error message.',
            ),
            RunStatus::Cancelled, RunStatus::Cancelling => $this->handoffRenderer->formatChildCancelledMessage(
                $identity->displayName,
                $identity->artifactId,
            ),
            default => throw new \RuntimeException('Deferred single subagent delivery cannot build presentation for non-terminal child status.'),
        };
    }

    private function resolveProgressStatus(
        DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection,
        ?DeferredSingleSubagentInterruptionKindEnum $interruptionKind,
    ): string {
        if (DeferredSingleSubagentInterruptionKindEnum::Timeout === $interruptionKind) {
            return 'failed';
        }
        if (DeferredSingleSubagentInterruptionKindEnum::ParentCancelled === $interruptionKind) {
            return 'cancelled';
        }

        return $this->mapChildProgressStatus($childProjection->childStatus);
    }

    private function mapChildProgressStatus(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::WaitingHuman => 'waiting_human',
            RunStatus::Completed => 'completed',
            RunStatus::Failed => 'failed',
            RunStatus::Cancelled, RunStatus::Cancelling => 'cancelled',
            default => 'running',
        };
    }

    private function completedSummaryText(DeferredSingleSubagentChildLifecycleProjectionDTO $childProjection): string
    {
        $text = trim($childProjection->assistantResultText ?? '');
        if ('' !== $text) {
            return $text;
        }

        return 'Completed with status completed.';
    }

    private function resolveTimeoutSeconds(DeferredSingleSubagentProjectionDTO $projection): int
    {
        if (null !== $projection->deadlineAt) {
            $anchor = $projection->startedAt ?? $projection->createdAt;
            $seconds = $projection->deadlineAt->getTimestamp() - $anchor->getTimestamp();

            return max(1, $seconds);
        }

        return 1;
    }

    private function elapsedMsSince(?\DateTimeImmutable $startedAt): int
    {
        if (null === $startedAt) {
            return 0;
        }

        $now = $this->clock->now();
        $delta = $now->getTimestamp() - $startedAt->getTimestamp();

        return max(0, $delta * 1000);
    }
}
