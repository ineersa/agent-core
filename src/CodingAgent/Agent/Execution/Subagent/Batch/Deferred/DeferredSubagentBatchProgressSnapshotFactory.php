<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchItemSnapshotDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunBatchProgressService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummary;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MonotonicClock;

/** Pure aggregate payload assembly for deferred batch progress. Delivery owns revision, append, markers. */
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
     * Build normal progress payload, branching on execution mode.
     *
     * @return array<string, mixed>
     */
    public function buildNormalPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
        if (ChildRunBatchExecutionModeEnum::Single === $batch->executionMode) {
            return $this->buildSingleNormalPayload($batch);
        }

        return $this->buildParallelNormalPayload($batch);
    }

    /**
     * Build forced progress payload for interruption (single-child only).
     *
     * Single timeout → flat 'failed' payload.
     * Single parent-cancel → flat 'cancelled' payload.
     *
     * @return array<string, mixed>
     */
    public function buildSingleForcedPayload(DeferredSubagentBatchProjectionDTO $batch, string $forcedStatus): array
    {
        $child = $batch->children[0];
        $cp = $child->childLifecycleProjection;

        $enrichment = null !== $cp
            ? $this->childProgressSummaryBuilder->fromDeferredProjection($cp, $child->artifactId)
            : null;

        $turnNo = null !== $cp ? $cp->childTurnNo : 0;

        return $this->progressSnapshotBuilder->singleTerminalFromChildTurn(
            $forcedStatus,
            $child->agentName,
            $child->artifactId,
            $child->childRunId,
            $child->task,
            $turnNo,
            $this->elapsedMsSince($batch->startedAt),
            $enrichment,
        );
    }

    /**
     * Parallel forced parent-cancel payload: preserve terminal children;
     * override non-terminal and unprojected children to Cancelled + "Cancelled by parent run.".
     *
     * @return array<string, mixed>
     */
    public function buildForcedCancelPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
        $reports = $activeTurns = $snapshots = $enrichmentByRun = [];
        foreach ($batch->children as $child) {
            $built = $this->buildForcedCancelChildRow($batch, $child);
            $snapshots[$child->childRunId] = $built['snapshot'];
            $activeTurns[$child->childRunId] = $built['turnNo'];
            $reports[$child->childRunId] = $built['report'];
            if (null !== $built['enrichment']) {
                $enrichmentByRun[$child->childRunId] = $built['enrichment'];
            }
        }

        return $this->progressSnapshotBuilder->parallelSnapshot(
            $reports, $activeTurns, $this->elapsedMsSince($batch->startedAt), $enrichmentByRun, 'cancelled',
        );
    }

    /**
     * Single-child flat normal payload: produces mode=single flat payload analogous to
     * the dedicated-single DeferredSingleSubagentTerminalCompletionService oracle.
     *
     * @return array<string, mixed>
     */
    private function buildSingleNormalPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
        $child = $batch->children[0];
        $cp = $child->childLifecycleProjection;

        $enrichment = null !== $cp
            ? $this->childProgressSummaryBuilder->fromDeferredProjection($cp, $child->artifactId)
            : null;

        $turnNo = null !== $cp ? $cp->childTurnNo : 0;
        $elapsed = $this->elapsedMsSince($batch->startedAt);

        if (null !== $cp && $cp->childStatus->isTerminal()) {
            $status = $this->mapChildProgressStatus($cp->childStatus);

            return $this->progressSnapshotBuilder->singleTerminalFromChildTurn(
                $status,
                $child->agentName,
                $child->artifactId,
                $child->childRunId,
                $child->task,
                $turnNo,
                $elapsed,
                $enrichment,
            );
        }

        $status = null !== $cp
            ? $this->mapChildProgressStatus($cp->childStatus)
            : 'running';

        return $this->progressSnapshotBuilder->singleRunningFromChildTurn(
            $child->agentName,
            $child->artifactId,
            $child->childRunId,
            $child->task,
            $turnNo,
            $elapsed,
            $enrichment,
            $status,
        );
    }

    /** @return array<string, mixed> */
    private function buildParallelNormalPayload(DeferredSubagentBatchProjectionDTO $batch): array
    {
        $reports = $activeTurns = $snapshots = $enrichmentByRun = [];
        foreach ($batch->children as $child) {
            $built = $this->buildChildProgressRow($batch, $child);
            $snapshots[$child->childRunId] = $built['snapshot'];
            $activeTurns[$child->childRunId] = $built['turnNo'];
            $reports[$child->childRunId] = $built['report'];
            if (null !== $built['enrichment']) {
                $enrichmentByRun[$child->childRunId] = $built['enrichment'];
            }
        }

        return $this->progressSnapshotBuilder->parallelSnapshot(
            $reports, $activeTurns, $this->elapsedMsSince($batch->startedAt),
            $enrichmentByRun, $this->batchProgressService->resolveAggregateStatus($snapshots),
        );
    }

    /** @return array{snapshot: ChildRunBatchItemSnapshotDTO, report: array<string, mixed>, turnNo: int, enrichment: ?SubagentChildProgressSummary} */
    private function buildChildProgressRow(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): array {
        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $state = $this->resolveChildProgressState($child);

        return [
            'snapshot' => new ChildRunBatchItemSnapshotDTO(
                identity: $identity, terminal: $state->terminal,
                artifactStatus: $state->artifactStatus, message: $state->message,
            ),
            'report' => $this->buildChildReport($child, $state),
            'turnNo' => $state->turnNo,
            'enrichment' => $state->enrichment,
        ];
    }

    /** @return array{snapshot: ChildRunBatchItemSnapshotDTO, report: array<string, mixed>, turnNo: int, enrichment: ?SubagentChildProgressSummary} */
    private function buildForcedCancelChildRow(
        DeferredSubagentBatchProjectionDTO $batch,
        DeferredSubagentChildProjectionDTO $child,
    ): array {
        $identity = $this->outcomeFactory->identityFromChild($batch, $child);
        $cp = $child->childLifecycleProjection;

        // Preserve naturally terminal children
        if (null !== $cp && $cp->childStatus->isTerminal()) {
            $state = $this->resolveChildProgressState($child);

            return $this->forcedCancelResult($identity, $child, $state, $state->turnNo, $state->enrichment);
        }

        // Projected-nonterminal: preserve turnNo + enrichment, override terminal/status/message
        if (null !== $cp) {
            $rs = $this->resolveChildProgressState($child);
            $cs = new DeferredSubagentBatchChildProgressStateDTO(
                true, AgentArtifactStatusEnum::Cancelled, 'Cancelled by parent run.', $rs->turnNo, $rs->enrichment,
            );

            return $this->forcedCancelResult($identity, $child, $cs, $rs->turnNo, $rs->enrichment);
        }

        // Unprojected: no turn/enrichment
        return $this->forcedCancelResult(
            $identity, $child,
            new DeferredSubagentBatchChildProgressStateDTO(true, AgentArtifactStatusEnum::Cancelled, 'Cancelled by parent run.', 0, null),
            0, null,
        );
    }

    /** @return array{snapshot: ChildRunBatchItemSnapshotDTO, report: array<string, mixed>, turnNo: int, enrichment: ?SubagentChildProgressSummary} */
    private function forcedCancelResult(ChildRunIdentityDTO $identity, DeferredSubagentChildProjectionDTO $child, DeferredSubagentBatchChildProgressStateDTO $state, int $turnNo, ?SubagentChildProgressSummary $enrichment): array
    {
        return [
            'snapshot' => new ChildRunBatchItemSnapshotDTO(
                identity: $identity, terminal: $state->terminal, artifactStatus: $state->artifactStatus, message: $state->message,
            ),
            'report' => $this->buildChildReport($child, $state),
            'turnNo' => $turnNo,
            'enrichment' => $enrichment,
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
                terminal: true, artifactStatus: AgentArtifactStatusEnum::Failed,
                message: self::LAUNCH_FAILED_MESSAGE, turnNo: 0, enrichment: null,
            );
        }

        return new DeferredSubagentBatchChildProgressStateDTO(
            terminal: false, artifactStatus: AgentArtifactStatusEnum::Running,
            message: '', turnNo: 0, enrichment: null,
        );
    }

    /** @return array<string, mixed> */
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
