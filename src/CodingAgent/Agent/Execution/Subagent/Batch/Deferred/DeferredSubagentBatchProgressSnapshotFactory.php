<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Pure aggregate payload assembly for deferred batch progress — normal and forced-parent-cancel modes.
 *
 * Owns ordered child state resolution, report/snapshot/enrichment construction, and
 * final parallelSnapshot payload building. Delivery service owns revision gating, append,
 * and marker CAS side effects.
 */
final readonly class DeferredSubagentBatchProgressSnapshotFactory
{
    private const LAUNCH_FAILED_MESSAGE = 'Child run failed to launch.';

    public function __construct(
        private DeferredSubagentBatchChildOutcomeFactory $outcomeFactory,
        private SubagentChildProgressSummaryBuilder $childProgressSummaryBuilder,
        private SubagentProgressSnapshotBuilder $progressSnapshotBuilder,
        private ChildRunBatchProgressService $batchProgressService,
        private ClockInterface $clock = new MonotonicClock(),
    ) {
    }

    /**
     * Build the normal-mode parallel payload with enrichment and status precedence.
     *
     * @return array<string, mixed> the parallelSnapshot payload ready for SubagentProgressEventAppender
     */
    public function buildNormalPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
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

        return $this->progressSnapshotBuilder->parallelSnapshot(
            $reports,
            $activeTurns,
            $elapsedMs,
            $enrichmentByRun,
            $aggregateStatus,
        );
    }

    /**
     * Build forced parent-cancel payload: preserves naturally terminal children with their
     * projection enrichment; overrides every non-terminal and unprojected child to
     * terminal Cancelled + "Cancelled by parent run.".
     *
     * @return array<string, mixed>
     */
    public function buildForcedCancelPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
        $reports = [];
        $activeTurns = [];
        $snapshots = [];

        foreach ($batch->children as $child) {
            $built = $this->buildForcedCancelChildRow($batch, $child);
            $snapshots[$child->childRunId] = $built['snapshot'];
            $activeTurns[$child->childRunId] = $built['turnNo'];
            $reports[$child->childRunId] = $built['report'];
        }

        $elapsedMs = $this->elapsedMsSince($batch->startedAt);

        return $this->progressSnapshotBuilder->parallelSnapshot(
            $reports,
            $activeTurns,
            $elapsedMs,
            [],
            'cancelled',
        );
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
        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $state = $this->resolveChildProgressState($child);

        return [
            'snapshot' => new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: $state->terminal,
                artifactStatus: $state->artifactStatus,
                message: $state->message,
            ),
            'report' => $this->buildChildReport($child, $state),
            'turnNo' => $state->turnNo,
            'enrichment' => $state->enrichment,
        ];
    }

    /**
     * @return array{snapshot: ChildRunBatchItemSnapshotDTO, report: array<string, mixed>, turnNo: int}
     */
    private function buildForcedCancelChildRow(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): array {
        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $cp = $child->childLifecycleProjection;

        // Preserve naturally terminal children with full projection enrichment and turn numbers
        if (null !== $cp && $cp->childStatus->isTerminal()) {
            $state = $this->resolveChildProgressState($child);

            return [
                'snapshot' => new ChildRunBatchItemSnapshotDTO(
                    identity: $identity,
                    terminal: $state->terminal,
                    artifactStatus: $state->artifactStatus,
                    message: $state->message,
                ),
                'report' => $this->buildChildReport($child, $state),
                'turnNo' => $state->turnNo,
            ];
        }

        // Override non-terminal and unprojected children to Cancelled
        $cancelledState = new DeferredSubagentBatchChildProgressStateDTO(
            terminal: true,
            artifactStatus: AgentArtifactStatusEnum::Cancelled,
            message: 'Cancelled by parent run.',
            turnNo: 0,
            enrichment: null,
        );

        return [
            'snapshot' => new ChildRunBatchItemSnapshotDTO(
                identity: $identity,
                terminal: true,
                artifactStatus: AgentArtifactStatusEnum::Cancelled,
                message: 'Cancelled by parent run.',
            ),
            'report' => $this->buildChildReport($child, $cancelledState),
            'turnNo' => 0,
        ];
    }

    private function resolveChildProgressState(DeferredSubagentChildProjectionDTO $child): DeferredSubagentBatchChildProgressStateDTO
    {
        $projection = $child->childLifecycleProjection;
        if (null !== $projection) {
            return new DeferredSubagentBatchChildProgressStateDTO(
                terminal: $projection->childStatus->isTerminal(),
                artifactStatus: $this->artifactStatusFromProjection($projection->childStatus),
                message: $this->progressMessageFromProjection($projection),
                turnNo: $projection->childTurnNo,
                enrichment: $this->childProgressSummaryBuilder->fromDeferredProjection($projection, $child->artifactId),
            );
        }

        if (DeferredSubagentChildLaunchStatusEnum::Failed === $child->launchStatus) {
            return new DeferredSubagentBatchChildProgressStateDTO(
                terminal: true,
                artifactStatus: AgentArtifactStatusEnum::Failed,
                message: self::LAUNCH_FAILED_MESSAGE,
                turnNo: 0,
                enrichment: null,
            );
        }

        return new DeferredSubagentBatchChildProgressStateDTO(
            terminal: false,
            artifactStatus: AgentArtifactStatusEnum::Running,
            message: '',
            turnNo: 0,
            enrichment: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChildReport(DeferredSubagentChildProjectionDTO $child, DeferredSubagentBatchChildProgressStateDTO $state): array
    {
        return [
            'index' => $child->batchIndex,
            'agentName' => $child->agentName,
            'task' => $child->task,
            'artifactId' => $child->artifactId,
            'agentRunId' => $child->childRunId,
            'terminal' => $state->terminal,
            'status' => $state->artifactStatus,
            'message' => $state->message,
            'model' => $child->definitionModel,
        ];
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
