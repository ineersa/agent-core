<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Symfony\Component\Clock\Clock;

/**
 * @extends ServiceEntityRepository<DeferredSubagentBatch>
 */
final class DeferredSubagentBatchRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DeferredSubagentChildRepository $childRepository,
    ) {
        parent::__construct($registry, DeferredSubagentBatch::class);
    }

    public function findByParentRunAndToolCall(string $parentRunId, string $parentToolCallId): ?DeferredSubagentBatchProjectionDTO
    {
        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        return $row instanceof DeferredSubagentBatch ? $this->toDto($row) : null;
    }

    public function findEntityByLifecycleId(string $lifecycleId): ?DeferredSubagentBatch
    {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSubagentBatch ? $row : null;
    }

    /**
     * Atomically applies one child lifecycle projection and bumps aggregate progress revision when the child cursor advances.
     */
    public function applyBatchChildLifecycleProjection(
        string $batchLifecycleId,
        int $batchIndex,
        DeferredChildRunLifecycleProjectionDTO $projection,
        int $childEventCursor,
        int $expectedChildProjectionVersion,
        int $expectedBatchProjectionVersion,
        bool $bumpAggregateRevision,
    ): void {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $terminalAt = $projection->childStatus->isTerminal() ? $now : null;
        $terminalStatus = $projection->childStatus->isTerminal() ? $projection->childStatus->value : null;

        $conn->beginTransaction();
        try {
            $childAffected = $conn->executeStatement(
                'UPDATE deferred_subagent_child SET child_lifecycle_projection = :projection, child_event_cursor = :cursor,
                    terminal_completed_at = COALESCE(terminal_completed_at, :terminal_at),
                    terminal_status = COALESCE(terminal_status, :terminal_status),
                    updated_at = :now, projection_version = projection_version + 1
                 WHERE batch_lifecycle_id = :batch AND batch_index = :idx AND projection_version = :child_version',
                [
                    'projection' => json_encode($projection->toArray(), \JSON_THROW_ON_ERROR),
                    'cursor' => $childEventCursor,
                    'terminal_at' => $terminalAt,
                    'terminal_status' => $terminalStatus,
                    'now' => $now,
                    'batch' => $batchLifecycleId,
                    'idx' => $batchIndex,
                    'child_version' => $expectedChildProjectionVersion,
                ],
            );

            if (0 === $childAffected) {
                $childRow = $this->childRepository->findEntityByBatchLifecycleAndIndex($batchLifecycleId, $batchIndex);
                if ($childRow instanceof DeferredSubagentChild) {
                    throw OptimisticLockException::lockFailed($childRow);
                }

                throw new \RuntimeException('Deferred subagent child projection version conflict.');
            }

            if ($bumpAggregateRevision) {
                $batchAffected = $conn->executeStatement(
                    'UPDATE deferred_subagent_batch SET aggregate_progress_revision = aggregate_progress_revision + 1,
                        updated_at = :now, projection_version = projection_version + 1
                     WHERE lifecycle_id = :batch AND projection_version = :batch_version',
                    [
                        'now' => $now,
                        'batch' => $batchLifecycleId,
                        'batch_version' => $expectedBatchProjectionVersion,
                    ],
                );
                if (0 === $batchAffected) {
                    $batchRow = $this->findOneBy(['lifecycleId' => $batchLifecycleId]);
                    if ($batchRow instanceof DeferredSubagentBatch) {
                        throw OptimisticLockException::lockFailed($batchRow);
                    }

                    throw new \RuntimeException('Deferred subagent batch projection version conflict.');
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $em->clear();
    }

    public function markDeliveredProgressRevision(
        string $batchLifecycleId,
        int $deliveredProgressRevision,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $batchLifecycleId]);
        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for lifecycle "%s".', $batchLifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->deliveredProgressRevision = max($row->deliveredProgressRevision, $deliveredProgressRevision);
        $this->getEntityManager()->flush();
    }

    public function markTerminalCompletionEnqueued(
        string $batchLifecycleId,
        \DateTimeImmutable $enqueuedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $batchLifecycleId]);
        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for lifecycle "%s".', $batchLifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->terminalCompletionEnqueuedAt = $row->terminalCompletionEnqueuedAt ?? $enqueuedAt;
        $this->getEntityManager()->flush();
    }

    public function findByLifecycleId(string $lifecycleId): ?DeferredSubagentBatchProjectionDTO
    {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSubagentBatch ? $this->toDto($row) : null;
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string, artifactKind: string}> $childIntents
     */
    public function reserveBatch(
        string $lifecycleId,
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        ChildRunBatchExecutionModeEnum $executionMode,
        int $totalChildCount,
        \DateTimeImmutable $deadlineAt,
        array $childIntents,
    ): DeferredSubagentBatchProjectionDTO {
        $existing = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if ($existing instanceof DeferredSubagentBatch) {
            $this->assertBatchMatchesIntent($existing, $lifecycleId, $executionMode, $totalChildCount, $childIntents);

            return $this->toDto($existing);
        }

        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();

        try {
            $conn->beginTransaction();
            $conn->insert('deferred_subagent_batch', [
                'lifecycle_id' => $lifecycleId,
                'parent_run_id' => $parentRunId,
                'parent_turn_no' => $parentTurnNo,
                'parent_tool_call_id' => $parentToolCallId,
                'parent_order_index' => $parentOrderIndex,
                'execution_mode' => $executionMode->value,
                'total_child_count' => $totalChildCount,
                'launch_status' => DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                'aggregate_progress_revision' => 0,
                'delivered_progress_revision' => 0,
                'projection_version' => 1,
                'started_at' => null,
                'deadline_at' => $deadlineAt->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->childRepository->insertReservedChildren($lifecycleId, $childIntents, $conn);

            $conn->commit();
        } catch (UniqueConstraintViolationException) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $row = $this->findOneBy([
                'parentRunId' => $parentRunId,
                'parentToolCallId' => $parentToolCallId,
            ]);

            if (!$row instanceof DeferredSubagentBatch) {
                throw new \RuntimeException(\sprintf('Deferred subagent batch reserve conflict for parent "%s" tool call "%s" but row missing.', $parentRunId, $parentToolCallId));
            }

            $this->assertBatchMatchesIntent($row, $lifecycleId, $executionMode, $totalChildCount, $childIntents);

            return $this->toDto($row);
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->getEntityManager()->clear();

        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch reserve for parent "%s" tool call "%s" succeeded but row could not be loaded.', $parentRunId, $parentToolCallId));
        }

        return $this->toDto($row);
    }

    public function markLaunched(string $parentRunId, string $parentToolCallId, \DateTimeImmutable $startedAt): void
    {
        $row = $this->requireRow($parentRunId, $parentToolCallId);
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }
        if (DeferredSubagentBatchLaunchStatusEnum::Failed === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSubagentBatchLaunchStatusEnum::Launched;
        $row->startedAt = $row->startedAt ?? $startedAt;
        $this->getEntityManager()->flush();
    }

    public function markFailed(string $parentRunId, string $parentToolCallId): void
    {
        $row = $this->requireRow($parentRunId, $parentToolCallId);
        if (DeferredSubagentBatchLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSubagentBatchLaunchStatusEnum::Failed;
        $this->getEntityManager()->flush();
    }

    /**
     * Preparation failed before any runtime start: every child row becomes Failed and batch becomes Failed (forward-only).
     */
    public function applyLaunchFailurePreparation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE deferred_subagent_batch SET launch_status = :failed, updated_at = :now, projection_version = projection_version + 1
                 WHERE parent_run_id = :parent AND parent_tool_call_id = :tool AND launch_status = :reserved',
                [
                    'failed' => DeferredSubagentBatchLaunchStatusEnum::Failed->value,
                    'now' => $now,
                    'parent' => $parentRunId,
                    'tool' => $parentToolCallId,
                    'reserved' => DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                ],
            );
            $conn->executeStatement(
                'UPDATE deferred_subagent_child SET launch_status = :failed, updated_at = :now, projection_version = projection_version + 1
                 WHERE batch_lifecycle_id = :lifecycle AND launch_status = :reserved',
                [
                    'failed' => DeferredSubagentChildLaunchStatusEnum::Failed->value,
                    'now' => $now,
                    'lifecycle' => $lifecycleId,
                    'reserved' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                ],
            );
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->getEntityManager()->clear();
    }

    /**
     * @param list<int> $launchedBatchIndices batch indices whose runtime start succeeded before the failing index
     */
    public function applyLaunchFailureRuntime(
        string $parentRunId,
        string $parentToolCallId,
        string $lifecycleId,
        int $failureBatchIndex,
        array $launchedBatchIndices,
    ): void {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();
        $launchedSet = array_fill_keys($launchedBatchIndices, true);

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE deferred_subagent_batch SET launch_status = :failed, updated_at = :now, projection_version = projection_version + 1
                 WHERE parent_run_id = :parent AND parent_tool_call_id = :tool AND launch_status = :reserved',
                [
                    'failed' => DeferredSubagentBatchLaunchStatusEnum::Failed->value,
                    'now' => $now,
                    'parent' => $parentRunId,
                    'tool' => $parentToolCallId,
                    'reserved' => DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                ],
            );

            $children = $this->childRepository->findOrderedByBatchLifecycleId($lifecycleId);
            foreach ($children as $child) {
                if ($child->batchIndex < $failureBatchIndex && isset($launchedSet[$child->batchIndex])) {
                    $conn->executeStatement(
                        'UPDATE deferred_subagent_child SET launch_status = :launched, started_at = COALESCE(started_at, :now), updated_at = :now, projection_version = projection_version + 1
                         WHERE batch_lifecycle_id = :lifecycle AND batch_index = :idx AND launch_status = :reserved',
                        [
                            'launched' => DeferredSubagentChildLaunchStatusEnum::Launched->value,
                            'now' => $now,
                            'lifecycle' => $lifecycleId,
                            'idx' => $child->batchIndex,
                            'reserved' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                        ],
                    );

                    continue;
                }

                if ($child->batchIndex >= $failureBatchIndex) {
                    $conn->executeStatement(
                        'UPDATE deferred_subagent_child SET launch_status = :failed, updated_at = :now, projection_version = projection_version + 1
                         WHERE batch_lifecycle_id = :lifecycle AND batch_index = :idx AND launch_status = :reserved',
                        [
                            'failed' => DeferredSubagentChildLaunchStatusEnum::Failed->value,
                            'now' => $now,
                            'lifecycle' => $lifecycleId,
                            'idx' => $child->batchIndex,
                            'reserved' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                        ],
                    );
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->getEntityManager()->clear();
    }

    /**
     * @param list<int> $launchedBatchIndices children known started or artifact already beyond Pending
     */
    public function applyLaunchSuccessState(
        string $parentRunId,
        string $parentToolCallId,
        string $lifecycleId,
        \DateTimeImmutable $startedAt,
        array $launchedBatchIndices,
    ): void {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $started = $startedAt->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();
        $incompleteBatchTransition = false;
        $conn->beginTransaction();
        try {
            foreach ($launchedBatchIndices as $batchIndex) {
                $conn->executeStatement(
                    'UPDATE deferred_subagent_child SET launch_status = :launched, started_at = COALESCE(started_at, :started), updated_at = :now, projection_version = projection_version + 1
                     WHERE batch_lifecycle_id = :lifecycle AND batch_index = :idx AND launch_status = :reserved',
                    [
                        'launched' => DeferredSubagentChildLaunchStatusEnum::Launched->value,
                        'started' => $started,
                        'now' => $now,
                        'lifecycle' => $lifecycleId,
                        'idx' => $batchIndex,
                        'reserved' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                    ],
                );
            }

            // Child evidence commits first; batch becomes Launched only when every child row is Launched.
            // If known child indices were forward-marked but the all-child condition is not met, commit
            // child updates, leave batch Reserved, then signal incomplete transition after commit.
            $batchAffected = $conn->executeStatement(
                'UPDATE deferred_subagent_batch SET launch_status = :launched, started_at = COALESCE(started_at, :started), updated_at = :now, projection_version = projection_version + 1
                 WHERE parent_run_id = :parent AND parent_tool_call_id = :tool AND launch_status = :reserved
                   AND total_child_count = (
                     SELECT COUNT(*) FROM deferred_subagent_child
                     WHERE batch_lifecycle_id = :lifecycle AND launch_status = :child_launched
                   )',
                [
                    'launched' => DeferredSubagentBatchLaunchStatusEnum::Launched->value,
                    'started' => $started,
                    'now' => $now,
                    'parent' => $parentRunId,
                    'tool' => $parentToolCallId,
                    'reserved' => DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                    'lifecycle' => $lifecycleId,
                    'child_launched' => DeferredSubagentChildLaunchStatusEnum::Launched->value,
                ],
            );

            if (0 === $batchAffected) {
                $currentStatus = $conn->fetchOne(
                    'SELECT launch_status FROM deferred_subagent_batch WHERE parent_run_id = :parent AND parent_tool_call_id = :tool',
                    [
                        'parent' => $parentRunId,
                        'tool' => $parentToolCallId,
                    ],
                );
                if (DeferredSubagentBatchLaunchStatusEnum::Launched->value !== $currentStatus) {
                    $incompleteBatchTransition = true;
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->getEntityManager()->clear();

        if ($incompleteBatchTransition) {
            throw new \RuntimeException('Deferred subagent batch launch success persistence left batch Reserved because not all child launch rows are Launched.');
        }
    }

    /**
     * @return list<DeferredSubagentBatchProjectionDTO>
     */
    public function findUnfinishedByParentRunId(string $parentRunId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.parentRunId = :parentRunId')
            ->andWhere('b.terminalCompletionEnqueuedAt IS NULL')
            ->andWhere('b.launchStatus IN (:statuses)')
            ->setParameter('parentRunId', $parentRunId)
            ->setParameter('statuses', [
                DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                DeferredSubagentBatchLaunchStatusEnum::Launched->value,
            ]);

        return array_map($this->toDto(...), $qb->getQuery()->getResult());
    }

    /**
     * Persist first-wins interruption intent with optimistic lock guard.
     */
    public function persistInterruptionIntent(
        string $batchLifecycleId,
        DeferredSubagentInterruptionKindEnum $kind,
        \DateTimeImmutable $requestedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $batchLifecycleId]);
        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for lifecycle "%s".', $batchLifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        if (null !== $row->terminalCompletionEnqueuedAt) {
            return;
        }

        if (null !== $row->interruptionKind) {
            return;
        }

        $row->interruptionKind = $kind;
        $row->interruptionRequestedAt = $requestedAt;
        $this->getEntityManager()->flush();
    }

    /**
     * Durable first-wins marker that interruption terminal progress was emitted.
     */
    public function markInterruptionProgressEnqueued(
        string $batchLifecycleId,
        \DateTimeImmutable $enqueuedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $batchLifecycleId]);
        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for lifecycle "%s".', $batchLifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        if (null !== $row->interruptionProgressEnqueuedAt) {
            return;
        }

        $row->interruptionProgressEnqueuedAt = $enqueuedAt;
        $this->getEntityManager()->flush();
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string, artifactKind: string}> $childIntents
     */
    private function assertBatchMatchesIntent(
        DeferredSubagentBatch $row,
        string $lifecycleId,
        ChildRunBatchExecutionModeEnum $executionMode,
        int $totalChildCount,
        array $childIntents,
    ): void {
        if ($row->lifecycleId !== $lifecycleId) {
            throw new ToolCallException('Deferred subagent batch for this tool call uses a different lifecycle id than the deterministic launch.', retryable: false);
        }

        if ($row->executionMode !== $executionMode || $row->totalChildCount !== $totalChildCount) {
            throw new ToolCallException('Deferred subagent batch for this tool call was reserved with a different execution mode or child count.', retryable: false);
        }

        $children = $this->childRepository->findOrderedByBatchLifecycleId($row->lifecycleId);
        if (\count($children) !== \count($childIntents)) {
            throw new ToolCallException('Deferred subagent batch child count does not match the ordered launch intent.', retryable: false);
        }

        foreach ($childIntents as $intent) {
            $match = null;
            foreach ($children as $child) {
                if ($child->batchIndex === $intent['batchIndex']) {
                    $match = $child;
                    break;
                }
            }

            if (null === $match) {
                throw new ToolCallException('Deferred subagent batch is missing a child row for the ordered launch intent.', retryable: false);
            }

            if ($match->childRunId !== $intent['childRunId']
                || $match->artifactId !== $intent['artifactId']
                || $match->agentName !== $intent['agentName']
                || $match->task !== $intent['task']) {
                throw new ToolCallException('Deferred subagent batch child intent does not match the durable reservation.', retryable: false);
            }

            if (null !== $intent['definitionModel'] && $match->definitionModel !== $intent['definitionModel']) {
                throw new ToolCallException('Deferred subagent batch child was reserved with a different model.', retryable: false);
            }
        }
    }

    private function requireRow(string $parentRunId, string $parentToolCallId): DeferredSubagentBatch
    {
        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for parent "%s" tool call "%s".', $parentRunId, $parentToolCallId));
        }

        return $row;
    }

    private function toDto(DeferredSubagentBatch $row): DeferredSubagentBatchProjectionDTO
    {
        return new DeferredSubagentBatchProjectionDTO(
            lifecycleId: $row->lifecycleId,
            parentRunId: $row->parentRunId,
            parentTurnNo: $row->parentTurnNo,
            parentToolCallId: $row->parentToolCallId,
            parentOrderIndex: $row->parentOrderIndex,
            executionMode: $row->executionMode,
            totalChildCount: $row->totalChildCount,
            launchStatus: $row->launchStatus,
            aggregateProgressRevision: $row->aggregateProgressRevision,
            deliveredProgressRevision: $row->deliveredProgressRevision,
            terminalCompletionEnqueuedAt: $row->terminalCompletionEnqueuedAt,
            startedAt: $row->startedAt,
            deadlineAt: $row->deadlineAt,
            createdAt: $row->createdAt,
            projectionVersion: $row->projectionVersion,
            children: $this->childRepository->findOrderedByBatchLifecycleId($row->lifecycleId),
            interruptionKind: $row->interruptionKind,
            interruptionRequestedAt: $row->interruptionRequestedAt,
            interruptionProgressEnqueuedAt: $row->interruptionProgressEnqueuedAt,
        );
    }
}
