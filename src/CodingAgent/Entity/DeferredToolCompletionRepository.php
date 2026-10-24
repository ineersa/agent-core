<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @extends ServiceEntityRepository<DeferredToolCompletion>
 */
final class DeferredToolCompletionRepository extends ServiceEntityRepository implements DeferredToolCompletionRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ToolPayloadStoreInterface $payloads,
        private readonly ToolBatchStoreInterface $batches,
        private readonly RunLockManager $locks,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct($registry, DeferredToolCompletion::class);
    }

    /** @param list<string> $runIds
     * @return list<array<string, mixed>> */
    public function pendingCodeMode(array $runIds): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT deferred_id, run_id, tool_call_id, ready_at, backgrounded_at, interruption_reason, created_at FROM tool_deferred_completion WHERE run_id IN (?) AND tool_name = 'code_mode' AND status = 'pending' AND (backgrounded_at IS NOT NULL OR accepted_result_reference_json IS NULL) ORDER BY created_at LIMIT 100",
            [$runIds], [ArrayParameterType::STRING],
        );
    }

    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        return $this->locks->synchronized($correlation->runId, fn () => $this->registerLocked($correlation));
    }

    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        return $this->locks->synchronized($runId, function () use ($runId, $toolCallId): ?DeferredToolCompletionCorrelation {
            $entity = $this->findFreshOneBy(['runId' => $runId, 'toolCallId' => $toolCallId]);

            return $entity instanceof DeferredToolCompletion && 'pending' === $entity->status ? $this->toCorrelation($entity) : null;
        });
    }

    public function statusForRunAndToolCall(string $runId, string $toolCallId): ?string
    {
        $entity = $this->findFreshOneBy(['runId' => $runId, 'toolCallId' => $toolCallId]);

        return $entity instanceof DeferredToolCompletion ? $entity->status : null;
    }

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            return null;
        }

        return $this->locks->synchronized($entity->runId, function () use ($deferredId): ?DeferredToolCompletionCorrelation {
            $fresh = $this->findFreshOneBy(['deferredId' => $deferredId]);

            return $fresh instanceof DeferredToolCompletion && 'pending' === $fresh->status ? $this->toCorrelation($fresh) : null;
        });
    }

    public function findMetadataByDeferredId(string $deferredId): ?DeferredToolCompletionMetadataDTO
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            return null;
        }
        $reference = $this->serializer->deserialize($entity->bodyReferenceJson, ToolPayloadReferenceDTO::class, 'json');
        $errors = $this->validator->validate($reference);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($reference, $errors);
        }
        $identity = [$reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex, $reference->toolName];
        $storedIdentity = [$entity->runId, $entity->turnNo, $entity->stepId, $entity->toolCallId, $entity->attempt, $entity->idempotencyKey, $entity->orderIndex, $entity->toolName];
        if ('correlation' !== $reference->kind || $identity !== $storedIdentity || !\in_array($entity->status, ['pending', 'completed'], true)) {
            throw new ToolPayloadException('Deferred correlation reference does not match its index.');
        }

        $accepted = null;
        if (null !== $entity->acceptedResultReferenceJson) {
            $accepted = $this->serializer->deserialize($entity->acceptedResultReferenceJson, ToolPayloadReferenceDTO::class, 'json');
            $errors = $this->validator->validate($accepted);
            if (0 !== \count($errors)) {
                throw new ValidationFailedException($accepted, $errors);
            }
            if ('result' !== $accepted->kind || [$accepted->runId, $accepted->turnNo, $accepted->stepId, $accepted->toolCallId, $accepted->attempt, $accepted->idempotencyKey, $accepted->orderIndex]
                !== [$entity->runId, $entity->turnNo, $entity->stepId, $entity->toolCallId, $entity->attempt, $entity->idempotencyKey, $entity->orderIndex]) {
                throw new ToolPayloadException('Deferred accepted result reference does not match its index.');
            }
        }

        return new DeferredToolCompletionMetadataDTO($entity->deferredId, $reference, $entity->status, $entity->readyAt, $entity->backgroundedAt, $accepted, $entity->interruptionReason, $entity->continuationRequired);
    }

    /** @return list<string> */
    public function codeModeIdentities(string $runId): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn("SELECT deferred_id FROM tool_deferred_completion WHERE run_id = ? AND tool_name = 'code_mode'", [$runId]);
    }

    public function interrupt(string $deferredId, string $reason): void
    {
        $entity = $this->require($deferredId);
        $entity->interruptionReason ??= $reason;
        $this->getEntityManager()->flush();
    }

    /** @return list<string> */
    public function controllerLossIdentities(string $runId): array
    {
        // A completed source can still own a mailbox receipt or an unconsumed
        // successor in its original turn. Historical completed turns cannot.
        return $this->getEntityManager()->getConnection()->fetchFirstColumn("SELECT DISTINCT d.deferred_id FROM tool_deferred_completion d LEFT JOIN background_completion_receipt r ON r.deferred_id = d.deferred_id LEFT JOIN run_operational_state s ON s.run_id = d.run_id WHERE d.run_id = ? AND d.tool_name = 'code_mode' AND (d.status = 'pending' OR d.continuation_required = 1 OR r.disposition IN ('pending', 'forwarding') OR (s.status = 'running' AND s.turn_no = d.turn_no AND s.active_step_id = d.step_id))", [$runId]);
    }

    public function runRequiresContinuation(string $runId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT 1 FROM tool_deferred_completion d LEFT JOIN background_completion_receipt r ON r.deferred_id = d.deferred_id WHERE d.continuation_required = 1 AND (d.run_id = ? OR r.destination_run_id = ?) LIMIT 1', [$runId, $runId]);
    }

    public function requireContinuation(string $id, bool $required): void
    {
        $this->require($id)->continuationRequired = $required;
        $this->getEntityManager()->flush();
    }

    /** @return list<string> */
    public function continuationRunIds(string $ownerRunId): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn("SELECT DISTINCT d.run_id FROM tool_deferred_completion d LEFT JOIN run_operational_state s ON s.run_id = d.run_id LEFT JOIN background_completion_receipt r ON r.deferred_id = d.deferred_id WHERE (d.continuation_required = 1 OR (d.status = 'pending' AND d.interruption_reason = 'controller_lost')) AND (d.run_id = ? OR s.owner_session_id = ? OR r.destination_run_id = ? OR r.root_run_id = ?)", array_fill(0, 4, $ownerRunId));
    }

    public function status(string $deferredId): ?string
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $entity->status : null;
    }

    public function markCompleted(string $deferredId): void
    {
        $em = $this->getEntityManager();
        $updated = $em->getConnection()->executeStatement(
            'UPDATE tool_deferred_completion SET status = :completed, updated_at = :now WHERE deferred_id = :deferred_id AND status = :pending',
            [
                'completed' => 'completed',
                'pending' => 'pending',
                'deferred_id' => $deferredId,
                'now' => Clock::get()->now()->format('Y-m-d H:i:s'),
            ],
        );

        if ($updated > 0) {
            // DBAL write bypasses the identity map; detach any stale managed row so status()/find* read fresh state.
            $managed = $this->findOneBy(['deferredId' => $deferredId]);
            if ($managed instanceof DeferredToolCompletion) {
                $em->detach($managed);
            }

            return;
        }

        // Fallback only runs when the conditional SQL update matched zero rows.
        // Refresh before deciding so a cached pending status cannot force a second write.
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            return;
        }

        if ('completed' === $entity->status) {
            return;
        }

        $entity->status = 'completed';
        $em->flush();
    }

    public function markReady(string $deferredId): void
    {
        $entity = $this->require($deferredId);
        $this->locks->synchronized($entity->runId, function () use ($deferredId): void {
            $fresh = $this->require($deferredId);
            if (null !== $fresh->readyAt) {
                return;
            }
            if ('pending' !== $fresh->status) {
                throw new ToolPayloadException('Deferred completion is no longer pending for readiness.');
            }
            $fresh->readyAt = Clock::get()->now();
            $this->getEntityManager()->flush();
        });
    }

    public function markBackgrounded(string $deferredId, \DateTimeImmutable $acceptedAt): void
    {
        $entity = $this->require($deferredId);
        $this->locks->synchronized($entity->runId, function () use ($deferredId, $acceptedAt): void {
            $fresh = $this->require($deferredId);
            if (null !== $fresh->backgroundedAt) {
                return;
            }
            if ('pending' !== $fresh->status || null === $fresh->readyAt || null !== $fresh->acceptedResultReferenceJson) {
                throw new ToolPayloadException('Deferred completion is not ready for backgrounding.');
            }
            $fresh->backgroundedAt = $acceptedAt;
            $this->getEntityManager()->flush();
        });
    }

    public function acceptResultReference(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO
    {
        $this->validateReference($reference);
        if ('result' !== $reference->kind) {
            throw new ToolPayloadException('Deferred accepted result must be a result reference.');
        }
        $entity = $this->require($deferredId);

        return $this->locks->synchronized($entity->runId, function () use ($deferredId, $reference): ToolPayloadReferenceDTO {
            $fresh = $this->require($deferredId);
            if ([$reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex]
                !== [$fresh->runId, $fresh->turnNo, $fresh->stepId, $fresh->toolCallId, $fresh->attempt, $fresh->idempotencyKey, $fresh->orderIndex]) {
                throw new ToolPayloadException('Deferred accepted result reference does not match its index.');
            }
            if (null !== $fresh->acceptedResultReferenceJson) {
                $existing = $this->serializer->deserialize($fresh->acceptedResultReferenceJson, ToolPayloadReferenceDTO::class, 'json');
                $this->validateReference($existing);
                if ([$existing->sha256, $existing->bytes] !== [$reference->sha256, $reference->bytes]) {
                    throw new ToolPayloadException('Deferred completion already accepted a different result.');
                }

                return $existing;
            }
            $fresh->acceptedResultReferenceJson = $this->serializer->serialize($reference, 'json');
            $this->getEntityManager()->flush();

            return $reference;
        });
    }

    private function require(string $deferredId): DeferredToolCompletion
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            throw new ToolPayloadException('Deferred completion has no registered correlation.');
        }

        return $entity;
    }

    private function validateReference(ToolPayloadReferenceDTO $reference): void
    {
        $errors = $this->validator->validate($reference);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($reference, $errors);
        }
    }

    private function registerLocked(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $existing = $this->findFreshOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if ($existing instanceof DeferredToolCompletion) {
            return $this->toCorrelation($existing);
        }

        $call = $this->batches->loadCallReference($correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->toolCallId);
        $schedule = $this->batches->loadSchedule($correlation->runId, $correlation->turnNo, $correlation->stepId);
        if (null === $call || $call->attempt !== $correlation->attempt || $call->idempotencyKey !== $correlation->idempotencyKey || null === $schedule || !isset($schedule->inFlight[$correlation->toolCallId]) || isset($schedule->completedIds[$correlation->toolCallId])) {
            throw new ToolPayloadException('Deferred registration no longer has an active invocation.');
        }
        $reference = $this->payloads->publishCorrelation($correlation);
        $now = Clock::get()->now()->format('Y-m-d H:i:s');

        try {
            $this->getEntityManager()->getConnection()->insert('tool_deferred_completion', [
                'deferred_id' => $correlation->deferredId,
                'run_id' => $correlation->runId,
                'turn_no' => $correlation->turnNo,
                'step_id' => $correlation->stepId,
                'attempt' => $correlation->attempt,
                'idempotency_key' => $correlation->idempotencyKey,
                'tool_call_id' => $correlation->toolCallId,
                'tool_name' => $correlation->toolName,
                'body_reference_json' => $this->serializer->serialize($reference, 'json'),
                'order_index' => $correlation->orderIndex,
                'tool_idempotency_key' => $correlation->toolIdempotencyKey,
                'mode' => $correlation->mode,
                'timeout_seconds' => $correlation->timeoutSeconds,
                'max_parallelism' => $correlation->maxParallelism,
                'tools_ref' => $correlation->toolsRef,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            return $this->resolveRegistrationConflict($correlation, $exception);
        }

        $inserted = $this->findOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if (!$inserted instanceof DeferredToolCompletion) {
            throw new \RuntimeException(\sprintf('Deferred tool registration for run "%s" tool call "%s" succeeded but row could not be loaded.', $correlation->runId, $correlation->toolCallId));
        }

        return $this->toCorrelation($inserted);
    }

    private function resolveRegistrationConflict(
        DeferredToolCompletionCorrelation $correlation,
        UniqueConstraintViolationException $exception,
    ): DeferredToolCompletionCorrelation {
        $byRunTool = $this->findFreshOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if ($byRunTool instanceof DeferredToolCompletion) {
            return $this->toCorrelation($byRunTool);
        }

        $byDeferredId = $this->findFreshOneBy(['deferredId' => $correlation->deferredId]);
        if ($byDeferredId instanceof DeferredToolCompletion) {
            if ($byDeferredId->runId !== $correlation->runId || $byDeferredId->toolCallId !== $correlation->toolCallId) {
                throw new \RuntimeException(\sprintf('Deferred id "%s" is already registered for run "%s" tool call "%s"; cannot register run "%s" tool call "%s".', $correlation->deferredId, $byDeferredId->runId, $byDeferredId->toolCallId, $correlation->runId, $correlation->toolCallId));
            }

            return $this->toCorrelation($byDeferredId);
        }

        throw $exception;
    }

    /**
     * Load one row with committed field values for mutable status decisions.
     *
     * Status-bearing reads overwrite identity-map copies before deciding
     * whether a correlation body must still exist.
     *
     * @param array<string, mixed> $criteria
     */
    private function findFreshOneBy(array $criteria): ?DeferredToolCompletion
    {
        $qb = $this->createQueryBuilder('d');
        $i = 0;
        foreach ($criteria as $field => $value) {
            $param = 'p'.$i;
            $qb->andWhere(\sprintf('d.%s = :%s', $field, $param))
                ->setParameter($param, $value);
            ++$i;
        }

        $entity = $qb->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof DeferredToolCompletion ? $entity : null;
    }

    private function toCorrelation(DeferredToolCompletion $entity): DeferredToolCompletionCorrelation
    {
        // Completed rows retain only their bounded dedupe identity. Their data
        // may have been legitimately removed after canonical batch settlement.
        if ('pending' !== $entity->status) {
            throw new ToolPayloadException('Completed deferred invocation has no live correlation body.');
        }
        $reference = $this->serializer->deserialize($entity->bodyReferenceJson, ToolPayloadReferenceDTO::class, 'json');
        $correlation = $this->payloads->readCorrelation($reference);
        if ($correlation->deferredId !== $entity->deferredId || $correlation->runId !== $entity->runId || $correlation->turnNo !== $entity->turnNo || $correlation->stepId !== $entity->stepId || $correlation->toolCallId !== $entity->toolCallId || $correlation->attempt !== $entity->attempt || $correlation->idempotencyKey !== $entity->idempotencyKey) {
            throw new ToolPayloadException('Deferred correlation reference does not match its index.');
        }

        return $correlation;
    }
}
