<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DTO\DeferredSubagentCompletionSourceDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Runtime\Contract\DTO\ChildBackgroundCandidateDTO;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @extends ServiceEntityRepository<DeferredSubagentBatch>
 */
final class DeferredSubagentBatchRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DeferredSubagentChildRepository $childRepository,
        private readonly SerializerInterface $serializer,
    ) {
        parent::__construct($registry, DeferredSubagentBatch::class);
    }

    public function findByParentRunAndToolCall(string $parentRunId, string $parentToolCallId): ?DeferredSubagentBatchProjectionDTO
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT * FROM deferred_subagent_batch WHERE parent_run_id = ? AND parent_tool_call_id = ?', [$parentRunId, $parentToolCallId],
        );

        return false === $row ? null : $this->snapshot($row);
    }

    public function findEntityByLifecycleId(string $lifecycleId): ?DeferredSubagentBatch
    {
        $row = $this->findFreshOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSubagentBatch ? $row : null;
    }

    /** @return list<ChildBackgroundCandidateDTO> */
    public function findBackgroundCandidates(string $parentRunId): array
    {
        return array_map($this->backgroundCandidate(...), $this->backgroundCandidateQuery($parentRunId)->getQuery()->getArrayResult());
    }

    public function findBackgroundCandidate(string $parentRunId, string $lifecycleId, string $toolCallId): ?ChildBackgroundCandidateDTO
    {
        $row = $this->backgroundCandidateQuery($parentRunId)
            ->andWhere('batch.lifecycleId = :lifecycleId')
            ->andWhere('batch.parentToolCallId = :toolCallId')
            ->setParameter('lifecycleId', $lifecycleId)
            ->setParameter('toolCallId', $toolCallId)
            ->getQuery()->getOneOrNullResult(Query::HYDRATE_ARRAY);

        return null === $row ? null : $this->backgroundCandidate($row);
    }

    public function markBackgrounded(string $lifecycleId, \DateTimeImmutable $acceptedAt): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE deferred_subagent_batch SET backgrounded_at = ?, updated_at = ?, projection_version = projection_version + 1 WHERE lifecycle_id = ? AND backgrounded_at IS NULL',
            [$acceptedAt->format('Y-m-d H:i:s'), $acceptedAt->format('Y-m-d H:i:s'), $lifecycleId],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findOwnedChildStatuses(string $rootRunId): array
    {
        // Child launches are top-level only. Read their operational status in
        // one query, independently of the already-settled parent tool call.
        // Failed preparation may leave no runtime or only a previous attempt's
        // projection. A successful launch retains its actual lifecycle status.
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT batch.lifecycle_id, batch.backgrounded_at,
                child.child_run_id, child.artifact_id, child.agent_name, child.task,
                child.launch_model, child.launch_reasoning,
                CASE WHEN child.launch_status = :failed_child_launch
                    OR (batch.launch_status = :failed_batch_launch AND child.launch_status = :reserved_child_launch)
                    THEN :failed_status
                    ELSE COALESCE(child.terminal_status, state.status,
                        json_extract(child.child_lifecycle_projection, '$.child_status'), 'queued') END AS status
            FROM deferred_subagent_batch batch
            JOIN deferred_subagent_child child ON child.batch_lifecycle_id = batch.lifecycle_id
            LEFT JOIN run_operational_state state ON state.run_id = child.child_run_id
            WHERE batch.parent_run_id = :root
            ORDER BY batch.id, child.batch_index
            SQL, [
            'root' => $rootRunId,
            'failed_child_launch' => DeferredSubagentChildLaunchStatusEnum::Failed->value,
            'failed_batch_launch' => DeferredSubagentBatchLaunchStatusEnum::Failed->value,
            'reserved_child_launch' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
            'failed_status' => RunStatus::Failed->value,
        ]);
    }

    public function hasLaunchedPendingParentToolCall(string $parentRunId, int $parentTurnNo, string $parentToolCallId): bool
    {
        $batch = $this->findByParentRunAndToolCall($parentRunId, $parentToolCallId);

        return null !== $batch
            && $batch->parentTurnNo === $parentTurnNo
            && DeferredSubagentBatchLaunchStatusEnum::Launched === $batch->launchStatus
            && null === $batch->backgroundedAt
            && null === $batch->terminalCompletionEnqueuedAt;
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
                    'projection' => $this->serializer->serialize(
                        $projection,
                        'json',
                        [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
                    ),
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
        $row = $this->findFreshOneBy(['lifecycleId' => $batchLifecycleId]);
        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch missing for lifecycle "%s".', $batchLifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->deliveredProgressRevision = max($row->deliveredProgressRevision, $deliveredProgressRevision);
        $this->getEntityManager()->flush();
    }

    public function freezeCompletionSource(
        string $lifecycleId,
        DeferredSubagentCompletionSourceDTO $source,
        int $expectedProjectionVersion,
    ): DeferredSubagentBatchProjectionDTO {
        $row = $this->findEntityByLifecycleId($lifecycleId)
            ?? throw new \RuntimeException('Cannot freeze completion for a missing deferred subagent batch.');
        if (null !== $row->completionSource) {
            return $this->toDto($row);
        }
        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->completionSource = $this->serializer->serialize($source, 'json');
        $this->getEntityManager()->flush();

        return $this->toDto($row);
    }

    public function markTerminalCompletionEnqueued(
        string $batchLifecycleId,
        \DateTimeImmutable $enqueuedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findFreshOneBy(['lifecycleId' => $batchLifecycleId]);
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
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT * FROM deferred_subagent_batch WHERE lifecycle_id = ?', [$lifecycleId],
        );

        return false === $row ? null : $this->snapshot($row);
    }

    public function reserveBatch(
        string $lifecycleId,
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        \DateTimeImmutable $deadlineAt,
        DeferredSubagentBatchChildIntentDTO $childIntent,
        ?string $parentModel = null,
    ): DeferredSubagentBatchProjectionDTO {
        $existing = $this->findFreshOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if ($existing instanceof DeferredSubagentBatch) {
            $this->assertBatchMatchesIntent($existing, $lifecycleId, $childIntent);

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
                'parent_model' => $parentModel,
                'launch_status' => DeferredSubagentBatchLaunchStatusEnum::Reserved->value,
                'aggregate_progress_revision' => 0,
                'delivered_progress_revision' => 0,
                'projection_version' => 1,
                'started_at' => null,
                'deadline_at' => $deadlineAt->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->childRepository->insertReservedChild($lifecycleId, $childIntent, $conn);

            $conn->commit();
        } catch (UniqueConstraintViolationException) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $row = $this->findFreshOneBy([
                'parentRunId' => $parentRunId,
                'parentToolCallId' => $parentToolCallId,
            ]);

            if (!$row instanceof DeferredSubagentBatch) {
                throw new \RuntimeException(\sprintf('Deferred subagent batch reserve conflict for parent "%s" tool call "%s" but row missing.', $parentRunId, $parentToolCallId));
            }

            $this->assertBatchMatchesIntent($row, $lifecycleId, $childIntent);

            return $this->toDto($row);
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        $this->getEntityManager()->clear();

        // Identity map was cleared after the SQL reservation; plain find is fine.
        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if (!$row instanceof DeferredSubagentBatch) {
            throw new \RuntimeException(\sprintf('Deferred subagent batch reserve for parent "%s" tool call "%s" succeeded but row could not be loaded.', $parentRunId, $parentToolCallId));
        }

        return $this->toDto($row);
    }

    /**
     * Preparation failed before any runtime start: every child row becomes Failed and batch becomes Failed (forward-only).
     */
    public function applyLaunchFailurePreparation(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();
        $ownTransaction = !$conn->isTransactionActive();

        if ($ownTransaction) {
            $conn->beginTransaction();
        }
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
            if ($ownTransaction) {
                $conn->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $conn->isTransactionActive()) {
                $conn->rollBack();
            }

            throw $e;
        }

        if ($ownTransaction) {
            $this->getEntityManager()->clear();
        }
    }

    public function applyLaunchSuccessState(
        string $parentRunId,
        string $parentToolCallId,
        string $lifecycleId,
        \DateTimeImmutable $startedAt,
    ): void {
        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $started = $startedAt->format('Y-m-d H:i:s');
        $conn = $this->getEntityManager()->getConnection();
        $incompleteBatchTransition = false;
        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'UPDATE deferred_subagent_child SET launch_status = :launched, started_at = COALESCE(started_at, :started), updated_at = :now, projection_version = projection_version + 1
                     WHERE batch_lifecycle_id = :lifecycle AND batch_index = :idx AND launch_status = :reserved',
                [
                    'launched' => DeferredSubagentChildLaunchStatusEnum::Launched->value,
                    'started' => $started,
                    'now' => $now,
                    'lifecycle' => $lifecycleId,
                    'idx' => 1,
                    'reserved' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                ],
            );

            // Child evidence commits first; the invocation becomes Launched only after its child.
            // If the child was not forward-marked, commit
            // pending updates, leave the invocation Reserved, and signal after commit.
            $batchAffected = $conn->executeStatement(
                'UPDATE deferred_subagent_batch SET launch_status = :launched, started_at = COALESCE(started_at, :started), updated_at = :now, projection_version = projection_version + 1
                 WHERE parent_run_id = :parent AND parent_tool_call_id = :tool AND launch_status = :reserved
                   AND 1 = (
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
     * Headers only: startup recovery and parent cancellation do not need children.
     *
     * @return list<DeferredSubagentBatch>
     */
    public function findUnfinishedHeadersByParentRunId(string $parentRunId): array
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

        return $qb->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
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
        $row = $this->findFreshOneBy(['lifecycleId' => $batchLifecycleId]);
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
        $row = $this->findFreshOneBy(['lifecycleId' => $batchLifecycleId]);
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

    private function backgroundCandidateQuery(string $parentRunId): QueryBuilder
    {
        $activeChild = $this->getEntityManager()->createQueryBuilder()
            ->select('1')->from(DeferredSubagentChild::class, 'child')
            ->where('child.batchLifecycleId = batch.lifecycleId')
            ->andWhere('child.terminalCompletedAt IS NULL');

        // Scalar eligibility only: picker snapshots must not hydrate child graphs
        // or decode their lifecycle projections. Acceptance loads the graph later.
        return $this->createQueryBuilder('batch')
            ->select('batch.lifecycleId, batch.parentToolCallId, completion.toolName')
            ->innerJoin(DeferredToolCompletion::class, 'completion', Join::WITH,
                'completion.deferredId = batch.lifecycleId AND completion.runId = batch.parentRunId AND completion.toolCallId = batch.parentToolCallId AND completion.turnNo = batch.parentTurnNo')
            ->where('batch.parentRunId = :parentRunId')
            ->andWhere('batch.launchStatus = :launched')
            ->andWhere('batch.backgroundedAt IS NULL')
            ->andWhere('batch.interruptionKind IS NULL')
            ->andWhere('batch.terminalCompletionEnqueuedAt IS NULL')
            ->andWhere('completion.status = :pending')
            ->andWhere('completion.toolName IN (:tools)')
            ->andWhere('EXISTS ('.$activeChild->getDQL().')')
            ->setParameter('parentRunId', $parentRunId)
            ->setParameter('launched', DeferredSubagentBatchLaunchStatusEnum::Launched)
            ->setParameter('pending', 'pending')
            ->setParameter('tools', ['subagent', 'fork', 'agent_resume'])
            ->orderBy('batch.id', 'ASC');
    }

    /** @param array{lifecycleId: string, parentToolCallId: string, toolName: string} $row */
    private function backgroundCandidate(array $row): ChildBackgroundCandidateDTO
    {
        return new ChildBackgroundCandidateDTO($row['lifecycleId'], $row['parentToolCallId'], \sprintf('%s %s', $row['toolName'], $row['parentToolCallId']));
    }

    private function assertBatchMatchesIntent(
        DeferredSubagentBatch $row,
        string $lifecycleId,
        DeferredSubagentBatchChildIntentDTO $childIntent,
    ): void {
        if ($row->lifecycleId !== $lifecycleId) {
            throw new ToolCallException('Deferred subagent batch for this tool call uses a different lifecycle id than the deterministic launch.', retryable: false);
        }

        $children = $this->childRepository->findOrderedByBatchLifecycleId($row->lifecycleId);
        if (1 !== \count($children)) {
            throw new ToolCallException('Deferred invocation requires exactly one reserved child.', retryable: false);
        }

        $child = $children[0];
        if ($child->batchIndex !== $childIntent->batchIndex
            || $child->childRunId !== $childIntent->childRunId
            || $child->artifactId !== $childIntent->artifactId
            || $child->agentName !== $childIntent->agentName
            || $child->task !== $childIntent->task
            || $child->launchModel !== $childIntent->launchModel
            || $child->launchReasoning !== $childIntent->launchReasoning) {
            throw new ToolCallException('Deferred invocation reservation does not match its launch intent.', retryable: false);
        }
    }

    /**
     * Load one batch with committed mutable fields (status/markers/version).
     *
     * @param array<string, mixed> $criteria
     */
    private function findFreshOneBy(array $criteria): ?DeferredSubagentBatch
    {
        $qb = $this->createQueryBuilder('b');
        $i = 0;
        foreach ($criteria as $field => $value) {
            $param = 'p'.$i;
            $qb->andWhere(\sprintf('b.%s = :%s', $field, $param))
                ->setParameter($param, $value);
            ++$i;
        }

        $row = $qb->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row instanceof DeferredSubagentBatch ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row): DeferredSubagentBatchProjectionDTO
    {
        return new DeferredSubagentBatchProjectionDTO(
            lifecycleId: $row['lifecycle_id'],
            parentRunId: $row['parent_run_id'],
            parentTurnNo: (int) $row['parent_turn_no'],
            parentToolCallId: $row['parent_tool_call_id'],
            parentOrderIndex: (int) $row['parent_order_index'],
            launchStatus: DeferredSubagentBatchLaunchStatusEnum::from($row['launch_status']),
            aggregateProgressRevision: (int) $row['aggregate_progress_revision'],
            deliveredProgressRevision: (int) $row['delivered_progress_revision'],
            terminalCompletionEnqueuedAt: null === $row['terminal_completion_enqueued_at'] ? null : new \DateTimeImmutable($row['terminal_completion_enqueued_at']),
            startedAt: null === $row['started_at'] ? null : new \DateTimeImmutable($row['started_at']),
            deadlineAt: null === $row['deadline_at'] ? null : new \DateTimeImmutable($row['deadline_at']),
            createdAt: new \DateTimeImmutable($row['created_at']),
            projectionVersion: (int) $row['projection_version'],
            children: $this->childRepository->findOrderedByBatchLifecycleId($row['lifecycle_id']),
            interruptionKind: null === $row['interruption_kind'] ? null : DeferredSubagentInterruptionKindEnum::from($row['interruption_kind']),
            interruptionRequestedAt: null === $row['interruption_requested_at'] ? null : new \DateTimeImmutable($row['interruption_requested_at']),
            interruptionProgressEnqueuedAt: null === $row['interruption_progress_enqueued_at'] ? null : new \DateTimeImmutable($row['interruption_progress_enqueued_at']),
            parentModel: $row['parent_model'],
            backgroundedAt: null === $row['backgrounded_at'] ? null : new \DateTimeImmutable($row['backgrounded_at']),
            completionSource: null === $row['completion_source'] ? null : $this->serializer->deserialize($row['completion_source'], DeferredSubagentCompletionSourceDTO::class, 'json'),
        );
    }

    private function toDto(DeferredSubagentBatch $row): DeferredSubagentBatchProjectionDTO
    {
        return $this->findByLifecycleId($row->lifecycleId)
            ?? throw new \RuntimeException('Deferred subagent batch disappeared before snapshot hydration.');
    }
}
