<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Doctrine\ORM\OptimisticLockException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
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
    private const LAUNCH_FAILED_MESSAGE = 'Child run failed to launch.';

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

        if ([] === $batch->children) {
            return false;
        }

        $reports = [];
        $activeTurns = [];
        $snapshots = [];
        $enrichmentByRun = [];

        foreach ($batch->children as $child) {
            $built = $this->buildChildProgressRow($batch, $child);
            $snapshots[$child->childRunId] = $built['snapshot'];
            $activeTurns[$child->childRunId] = $built['turnNo'];
            $reports[$child->childRunId] = $built['report'];
            if (null !== $built['enrichment']) {
                $enrichmentByRun[$child->childRunId] = $built['enrichment'];
            }
        }

        $aggregateStatus = $this->batchProgressService->resolveAggregateStatus($snapshots);
        $elapsedMs = $this->elapsedMsSince($batch->startedAt);

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

    /**
     * @return array{
     *     snapshot: ChildRunBatchItemSnapshotDTO,
     *     report: array<string, mixed>,
     *     turnNo: int,
     *     enrichment: ?\Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary
     * }
     */
    private function buildChildProgressRow(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): array {
        $identity = $this->identityFromChild($batch, $child);
        $projection = $child->childLifecycleProjection;

        if (null !== $projection) {
            $terminal = $projection->childStatus->isTerminal();
            $artifactStatus = $this->artifactStatusFromProjection($projection->childStatus);
            $message = $this->progressMessageFromProjection($projection);

            return [
                'snapshot' => new ChildRunBatchItemSnapshotDTO(
                    identity: $identity,
                    terminal: $terminal,
                    artifactStatus: $artifactStatus,
                    message: $message,
                ),
                'report' => [
                    'index' => $child->batchIndex,
                    'agentName' => $child->agentName,
                    'task' => $child->task,
                    'artifactId' => $child->artifactId,
                    'agentRunId' => $child->childRunId,
                    'terminal' => $terminal,
                    'status' => $artifactStatus,
                    'message' => $message,
                    'model' => $child->definitionModel,
                ],
                'turnNo' => $projection->childTurnNo,
                'enrichment' => $this->childProgressSummaryBuilder->fromDeferredProjection($projection, $child->artifactId),
            ];
        }

        if (DeferredSubagentChildLaunchStatusEnum::Failed === $child->launchStatus) {
            $artifactStatus = AgentArtifactStatusEnum::Failed;
            $message = self::LAUNCH_FAILED_MESSAGE;

            return [
                'snapshot' => new ChildRunBatchItemSnapshotDTO(
                    identity: $identity,
                    terminal: true,
                    artifactStatus: $artifactStatus,
                    message: $message,
                ),
                'report' => [
                    'index' => $child->batchIndex,
                    'agentName' => $child->agentName,
                    'task' => $child->task,
                    'artifactId' => $child->artifactId,
                    'agentRunId' => $child->childRunId,
                    'terminal' => true,
                    'status' => $artifactStatus,
                    'message' => $message,
                    'model' => $child->definitionModel,
                ],
                'turnNo' => 0,
                'enrichment' => null,
            ];
        }

        $artifactStatus = AgentArtifactStatusEnum::Running;

        return [
            'snapshot' => new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: false,
                artifactStatus: $artifactStatus,
                message: '',
            ),
            'report' => [
                'index' => $child->batchIndex,
                'agentName' => $child->agentName,
                'task' => $child->task,
                'artifactId' => $child->artifactId,
                'agentRunId' => $child->childRunId,
                'terminal' => false,
                'status' => $artifactStatus,
                'message' => '',
                'model' => $child->definitionModel,
            ],
            'turnNo' => 0,
            'enrichment' => null,
        ];
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

    private function progressMessageFromProjection(DeferredChildRunLifecycleProjectionDTO $projection): string
    {
        if (RunStatus::Failed === $projection->childStatus) {
            return $projection->errorMessage ?? 'Run failed without error message.';
        }

        if (RunStatus::Cancelled === $projection->childStatus || RunStatus::Cancelling === $projection->childStatus) {
            return 'Child run was cancelled.';
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
