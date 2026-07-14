<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Aggregate parallel parent progress from durable batch child projections (Piece 4B).
 */
final readonly class DeferredSubagentBatchProgressDeliveryService
{
    public function __construct(
        private DeferredSubagentBatchRepository $batchRepository,
        private SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private ChildRunBatchProgressService $batchProgressService,
        private SubagentProgressEventAppender $progressEventAppender,
        private LoggerInterface $logger,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    public function deliverIfNeeded(DeferredSubagentBatchProjectionDTO $batch): bool
    {
        if ($batch->aggregateProgressRevision <= $batch->deliveredProgressRevision) {
            return false;
        }

        $reports = [];
        $activeTurns = [];
        $snapshots = [];

        foreach ($batch->children as $child) {
            $projection = $child->childLifecycleProjection;
            if (null === $projection) {
                continue;
            }

            $terminal = $projection->childStatus->isTerminal();
            $artifactStatus = $this->artifactStatusFromProjection($projection->childStatus);
            $message = $this->messageFromProjection($projection);
            $identity = new ChildRunIdentityDTO(
                parentRunId: $batch->parentRunId,
                childRunId: $child->childRunId,
                artifactId: $child->artifactId,
                displayName: $child->agentName,
                taskSummary: $child->task,
                definitionModel: $child->definitionModel,
                artifactKind: \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent,
                batchIndex: $child->batchIndex,
            );
            $snapshots[$child->childRunId] = new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: $terminal,
                artifactStatus: $artifactStatus,
                message: $message,
            );
            $activeTurns[$child->childRunId] = $projection->childTurnNo;
            $reports[$child->childRunId] = [
                'index' => $child->batchIndex,
                'agentName' => $child->agentName,
                'task' => $child->task,
                'artifactId' => $child->artifactId,
                'agentRunId' => $child->childRunId,
                'terminal' => $terminal,
                'status' => $artifactStatus,
                'message' => $message,
                'model' => $child->definitionModel,
            ];
        }

        if ([] === $reports) {
            return false;
        }

        $aggregateStatus = $this->batchProgressService->resolveAggregateStatus($snapshots);
        $elapsedMs = $this->elapsedMsSince($batch->startedAt);
        $enrichmentByRun = [];
        foreach ($batch->children as $child) {
            if (null === $child->childLifecycleProjection) {
                continue;
            }
            $enrichmentByRun[$child->childRunId] = $this->childProgressSummaryBuilder->fromDeferredProjection(
                $child->childLifecycleProjection,
                $child->artifactId,
            );
        }

        $payload = $this->progressSnapshotBuilder->parallelSnapshot(
            $reports,
            $activeTurns,
            $elapsedMs,
            $enrichmentByRun,
            $aggregateStatus,
        );

        try {
            $this->progressEventAppender->append(
                parentRunId: $batch->parentRunId,
                parentTurnNo: $batch->parentTurnNo,
                parentToolCallId: $batch->parentToolCallId,
                parentOrderIndex: $batch->parentOrderIndex,
                toolName: 'subagent',
                progress: $payload,
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('deferred_subagent_batch.parent_progress_append_failed', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.parent_progress_append_failed',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        try {
            $this->batchRepository->markDeliveredProgressRevision(
                batchLifecycleId: $batch->lifecycleId,
                deliveredProgressRevision: $batch->aggregateProgressRevision,
                expectedProjectionVersion: $batch->projectionVersion,
            );
        } catch (OptimisticLockException $exception) {
            $this->logger->warning('deferred_subagent_batch.delivered_progress_revision_conflict', [
                'batch_lifecycle_id' => $batch->lifecycleId,
                'parent_run_id' => $batch->parentRunId,
                'tool_call_id' => $batch->parentToolCallId,
                'component' => 'agent.execution',
                'event_type' => 'deferred_subagent_batch.delivered_progress_revision_conflict',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        return true;
    }

    private function artifactStatusFromProjection(RunStatus $status): AgentArtifactStatusEnum
    {
        return match ($status) {
            RunStatus::Completed => AgentArtifactStatusEnum::Completed,
            RunStatus::Failed => AgentArtifactStatusEnum::Failed,
            RunStatus::Cancelled, RunStatus::Cancelling => AgentArtifactStatusEnum::Cancelled,
            RunStatus::WaitingHuman => AgentArtifactStatusEnum::NeedsClarification,
            default => AgentArtifactStatusEnum::Running,
        };
    }

    private function messageFromProjection(\Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO $projection): string
    {
        if (RunStatus::Failed === $projection->childStatus) {
            return $projection->errorMessage ?? 'Run failed without error message.';
        }

        $text = trim($projection->assistantResultText ?? '');
        if ('' !== $text) {
            return $text;
        }

        if (RunStatus::Completed === $projection->childStatus) {
            return 'Completed with status completed.';
        }

        return '';
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
