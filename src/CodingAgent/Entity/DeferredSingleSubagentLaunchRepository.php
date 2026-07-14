<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentInterruptionKindEnum;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DeferredSingleSubagentLaunch>
 */
final class DeferredSingleSubagentLaunchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeferredSingleSubagentLaunch::class);
    }

    public function findByChildRunId(string $childRunId): ?DeferredSingleSubagentProjectionDTO
    {
        $row = $this->findOneBy(['childRunId' => $childRunId]);

        return $row instanceof DeferredSingleSubagentLaunch ? $this->toDto($row) : null;
    }

    public function findByLifecycleId(string $lifecycleId): ?DeferredSingleSubagentProjectionDTO
    {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSingleSubagentLaunch ? $this->toDto($row) : null;
    }

    public function findEntityByLifecycleId(string $lifecycleId): ?DeferredSingleSubagentLaunch
    {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);

        return $row instanceof DeferredSingleSubagentLaunch ? $row : null;
    }

    public function applyChildLifecycleProjection(
        string $lifecycleId,
        DeferredChildRunLifecycleProjectionDTO $projection,
        int $childEventCursor,
        int $expectedProjectionVersion,
    ): void {
        $em = $this->getEntityManager();
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);
        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for lifecycle "%s".', $lifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->childLifecycleProjection = $projection->toArray();
        $row->childEventCursor = $childEventCursor;
        $em->flush();
    }

    public function markParentProgressCursor(
        string $lifecycleId,
        int $parentProgressCursor,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);
        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for lifecycle "%s".', $lifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->parentProgressCursor = $parentProgressCursor;
        $this->getEntityManager()->flush();
    }

    public function markParentProgressDelivery(
        string $lifecycleId,
        int $parentProgressCursor,
        ?\DateTimeImmutable $interruptionProgressEnqueuedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);
        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for lifecycle "%s".', $lifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->parentProgressCursor = max($row->parentProgressCursor, $parentProgressCursor);
        if (null !== $interruptionProgressEnqueuedAt) {
            $row->interruptionProgressEnqueuedAt = $row->interruptionProgressEnqueuedAt ?? $interruptionProgressEnqueuedAt;
        }

        $this->getEntityManager()->flush();
    }

    public function markTerminalCompletionEnqueued(
        string $lifecycleId,
        \DateTimeImmutable $enqueuedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);
        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for lifecycle "%s".', $lifecycleId));
        }

        if ($row->projectionVersion !== $expectedProjectionVersion) {
            throw OptimisticLockException::lockFailed($row);
        }

        $row->terminalCompletionEnqueuedAt = $row->terminalCompletionEnqueuedAt ?? $enqueuedAt;
        $this->getEntityManager()->flush();
    }

    /**
     * @return list<DeferredSingleSubagentProjectionDTO>
     */
    public function findActiveByParentRunId(string $parentRunId): array
    {
        return $this->findRecoverableByParentRunId($parentRunId);
    }

    /**
     * Unfinished deferred single rows for one parent session (Reserved post-dispatch or Launched).
     *
     * @return list<DeferredSingleSubagentProjectionDTO>
     */
    public function findRecoverableByParentRunId(string $parentRunId): array
    {
        $qb = $this->createQueryBuilder('d')
            ->andWhere('d.parentRunId = :parentRunId')
            ->andWhere('d.terminalCompletionEnqueuedAt IS NULL')
            ->andWhere('d.launchStatus IN (:statuses)')
            ->setParameter('parentRunId', $parentRunId)
            ->setParameter('statuses', [
                DeferredSingleSubagentLaunchStatusEnum::Reserved->value,
                DeferredSingleSubagentLaunchStatusEnum::Launched->value,
            ]);

        $out = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            if ($row instanceof DeferredSingleSubagentLaunch) {
                $out[] = $this->toDto($row);
            }
        }

        return $out;
    }

    public function persistInterruptionIntent(
        string $lifecycleId,
        DeferredSingleSubagentInterruptionKindEnum $kind,
        \DateTimeImmutable $requestedAt,
        int $expectedProjectionVersion,
    ): void {
        $row = $this->findOneBy(['lifecycleId' => $lifecycleId]);
        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for lifecycle "%s".', $lifecycleId));
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

    public function findByParentRunAndToolCall(string $parentRunId, string $toolCallId): ?DeferredSingleSubagentProjectionDTO
    {
        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $toolCallId,
        ]);

        return $row instanceof DeferredSingleSubagentLaunch ? $this->toDto($row) : null;
    }

    public function reserve(
        string $parentRunId,
        int $parentTurnNo,
        string $parentToolCallId,
        int $parentOrderIndex,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
        ?string $definitionModel,
        \DateTimeImmutable $deadlineAt,
    ): DeferredSingleSubagentProjectionDTO {
        $existing = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if ($existing instanceof DeferredSingleSubagentLaunch) {
            $this->assertMatchesIntent($existing, $childRunId, $artifactId, $agentName, $task, $definitionModel);

            return $this->toDto($existing);
        }

        $lifecycleId = Uuid::v7()->toRfc4122();
        $now = Clock::get()->now()->format('Y-m-d H:i:s');

        try {
            $this->getEntityManager()->getConnection()->insert('deferred_single_subagent_launch', [
                'lifecycle_id' => $lifecycleId,
                'parent_run_id' => $parentRunId,
                'parent_turn_no' => $parentTurnNo,
                'parent_tool_call_id' => $parentToolCallId,
                'parent_order_index' => $parentOrderIndex,
                'child_run_id' => $childRunId,
                'artifact_id' => $artifactId,
                'agent_name' => $agentName,
                'task' => $task,
                'definition_model' => $definitionModel,
                'launch_status' => DeferredSingleSubagentLaunchStatusEnum::Reserved->value,
                'child_event_cursor' => 0,
                'parent_progress_cursor' => 0,
                'projection_version' => 1,
                'started_at' => null,
                'deadline_at' => $deadlineAt->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $row = $this->findOneBy([
                'parentRunId' => $parentRunId,
                'parentToolCallId' => $parentToolCallId,
            ]);

            if (!$row instanceof DeferredSingleSubagentLaunch) {
                throw new \RuntimeException(\sprintf('Deferred single subagent reserve conflict for parent "%s" tool call "%s" but row missing.', $parentRunId, $parentToolCallId));
            }

            $this->assertMatchesIntent($row, $childRunId, $artifactId, $agentName, $task, $definitionModel);

            return $this->toDto($row);
        }

        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $parentToolCallId,
        ]);

        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent reserve for parent "%s" tool call "%s" succeeded but row could not be loaded.', $parentRunId, $parentToolCallId));
        }

        return $this->toDto($row);
    }

    public function markLaunched(string $parentRunId, string $toolCallId, \DateTimeImmutable $startedAt): void
    {
        $row = $this->requireRow($parentRunId, $toolCallId);

        if (DeferredSingleSubagentLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }

        if (DeferredSingleSubagentLaunchStatusEnum::Failed === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSingleSubagentLaunchStatusEnum::Launched;
        $row->startedAt = $row->startedAt ?? $startedAt;
        $this->getEntityManager()->flush();
    }

    public function markFailed(string $parentRunId, string $toolCallId): void
    {
        $row = $this->requireRow($parentRunId, $toolCallId);
        if (DeferredSingleSubagentLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSingleSubagentLaunchStatusEnum::Failed;
        $this->getEntityManager()->flush();
    }

    private function requireRow(string $parentRunId, string $toolCallId): DeferredSingleSubagentLaunch
    {
        $row = $this->findOneBy([
            'parentRunId' => $parentRunId,
            'parentToolCallId' => $toolCallId,
        ]);

        if (!$row instanceof DeferredSingleSubagentLaunch) {
            throw new \RuntimeException(\sprintf('Deferred single subagent projection missing for parent "%s" tool call "%s".', $parentRunId, $toolCallId));
        }

        return $row;
    }

    private function assertMatchesIntent(
        DeferredSingleSubagentLaunch $row,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
        ?string $definitionModel,
    ): void {
        if ($row->childRunId !== $childRunId || $row->artifactId !== $artifactId) {
            throw new ToolCallException('Deferred single subagent projection for this tool call uses a different child identity than the deterministic launch.', retryable: false);
        }

        if ($row->agentName !== $agentName || $row->task !== $task) {
            throw new ToolCallException('Deferred single subagent projection for this tool call was reserved for a different agent or task.', retryable: false);
        }

        if (null !== $definitionModel && $row->definitionModel !== $definitionModel) {
            throw new ToolCallException('Deferred single subagent projection for this tool call was reserved with a different model.', retryable: false);
        }
    }

    private function toDto(DeferredSingleSubagentLaunch $row): DeferredSingleSubagentProjectionDTO
    {
        return new DeferredSingleSubagentProjectionDTO(
            lifecycleId: $row->lifecycleId,
            parentRunId: $row->parentRunId,
            parentTurnNo: $row->parentTurnNo,
            parentToolCallId: $row->parentToolCallId,
            parentOrderIndex: $row->parentOrderIndex,
            childRunId: $row->childRunId,
            artifactId: $row->artifactId,
            agentName: $row->agentName,
            task: $row->task,
            definitionModel: $row->definitionModel,
            launchStatus: $row->launchStatus,
            childEventCursor: $row->childEventCursor,
            parentProgressCursor: $row->parentProgressCursor,
            terminalCompletionEnqueuedAt: $row->terminalCompletionEnqueuedAt,
            startedAt: $row->startedAt,
            deadlineAt: $row->deadlineAt,
            createdAt: $row->createdAt,
            interruptionKind: $row->interruptionKind,
            interruptionRequestedAt: $row->interruptionRequestedAt,
            interruptionProgressEnqueuedAt: $row->interruptionProgressEnqueuedAt,
            childLifecycleProjection: $this->decodeChildLifecycleProjection($row->childLifecycleProjection),
        );
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function decodeChildLifecycleProjection(?array $raw): ?DeferredChildRunLifecycleProjectionDTO
    {
        if (null === $raw || [] === $raw) {
            return null;
        }

        return DeferredChildRunLifecycleProjectionDTO::fromArray($raw);
    }
}
