<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeferredSubagentBatchProjectionDTO;
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

    public function findByLifecycleId(string $lifecycleId): ?DeferredSubagentBatchProjectionDTO
    {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSubagentBatch ? $this->toDto($row) : null;
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
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
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, definitionModel: ?string}> $childIntents
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
            projectionVersion: $row->projectionVersion,
            children: $this->childRepository->findOrderedByBatchLifecycleId($row->lifecycleId),
            interruptionKind: $row->interruptionKind,
            interruptionRequestedAt: $row->interruptionRequestedAt,
            interruptionProgressEnqueuedAt: $row->interruptionProgressEnqueuedAt,
        );
    }
}
