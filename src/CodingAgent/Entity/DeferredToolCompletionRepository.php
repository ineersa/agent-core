<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\Tool\DeferredAcceptedResultReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolInvocationFence;
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
        private readonly DeferredToolInvocationFence $fence,
        private readonly DeferredAcceptedResultReaderInterface $acceptedResults,
    ) {
        parent::__construct($registry, DeferredToolCompletion::class);
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

        // Pending rows still require a retained invocation reference; completed
        // markers keep only the bounded correlation identity.
        if ('pending' === $entity->status) {
            $this->pendingInvocationReference($entity);
        }

        return new DeferredToolCompletionMetadataDTO($entity->deferredId, $reference, $entity->status);
    }

    public function findInvocationReferenceByDeferredId(string $deferredId): ?ToolPayloadReferenceDTO
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion || 'pending' !== $entity->status) {
            return null;
        }

        return $this->pendingInvocationReference($entity)
            ?? throw new ToolPayloadException('Pending deferred registration is missing its invocation reference.');
    }

    public function findAcceptedResultReferenceByDeferredId(string $deferredId): ?ToolPayloadReferenceDTO
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion || ('pending' !== $entity->status && !$entity->continuationRequired)) {
            return null;
        }

        return $this->pendingAcceptedResultReference($entity);
    }

    public function status(string $deferredId): ?string
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $entity->status : null;
    }

    public function acknowledgedAt(string $deferredId): ?\DateTimeImmutable
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $entity->acknowledgedAt : null;
    }

    public function markAcknowledged(string $deferredId): void
    {
        $em = $this->getEntityManager();
        $now = Clock::get()->now();
        $updated = $em->getConnection()->executeStatement(
            'UPDATE tool_deferred_completion SET acknowledged_at = :acknowledged_at, updated_at = :now WHERE deferred_id = :deferred_id AND status = :pending AND acknowledged_at IS NULL',
            [
                'acknowledged_at' => $now->format('Y-m-d H:i:s'),
                'now' => $now->format('Y-m-d H:i:s'),
                'deferred_id' => $deferredId,
                'pending' => 'pending',
            ],
        );
        if ($updated > 0) {
            $managed = $this->findOneBy(['deferredId' => $deferredId]);
            if ($managed instanceof DeferredToolCompletion) {
                $em->detach($managed);
            }

            return;
        }
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion || null !== $entity->acknowledgedAt || 'pending' !== $entity->status) {
            return;
        }
        $entity->acknowledgedAt = $now;
        $em->flush();
    }

    public function requireContinuation(string $deferredId, bool $required): void
    {
        // A successful enqueue is not canonical caller admission. Reopen that
        // narrow waiting-caller gap while its active batch still retains bodies.
        // Already admitted background receipts keep their completed marker.
        $this->getEntityManager()->getConnection()->executeStatement(<<<'SQL'
            UPDATE tool_deferred_completion SET continuation_required = ?,
                status = CASE WHEN CAST(? AS INTEGER) = 1 AND acknowledged_at IS NULL
                    AND EXISTS (SELECT 1 FROM tool_batch_call c WHERE c.run_id = tool_deferred_completion.run_id AND c.turn_no = tool_deferred_completion.turn_no AND c.step_id = tool_deferred_completion.step_id AND c.tool_call_id = tool_deferred_completion.tool_call_id)
                    AND NOT EXISTS (SELECT 1 FROM tool_batch_result r WHERE r.run_id = tool_deferred_completion.run_id AND r.turn_no = tool_deferred_completion.turn_no AND r.step_id = tool_deferred_completion.step_id AND r.tool_call_id = tool_deferred_completion.tool_call_id)
                THEN 'pending' ELSE status END
            WHERE deferred_id = ?
            SQL, [(int) $required, (int) $required, $deferredId]);
    }

    public function requiresContinuation(string $runId, string $toolCallId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne('SELECT continuation_required FROM tool_deferred_completion WHERE run_id = ? AND tool_call_id = ?', [$runId, $toolCallId]);
    }

    public function acceptResult(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO
    {
        return $this->locks->synchronized(
            $reference->runId,
            function () use ($deferredId, $reference): ToolPayloadReferenceDTO {
                $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);
                if (!$entity instanceof DeferredToolCompletion || 'pending' !== $entity->status) {
                    throw new ToolPayloadException('Deferred registration cannot accept a terminal result.');
                }
                $existing = $this->pendingAcceptedResultReference($entity);
                if (null !== $existing) {
                    return $existing;
                }
                $invocation = $this->pendingInvocationReference($entity)
                    ?? throw new ToolPayloadException('Pending deferred registration is missing its invocation reference.');
                $identity = [$reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex, $reference->toolName];
                $storedIdentity = [$entity->runId, $entity->turnNo, $entity->stepId, $entity->toolCallId, $entity->attempt, $entity->idempotencyKey, $entity->orderIndex, $entity->toolName];
                if ('result' !== $reference->kind || $identity !== $storedIdentity) {
                    throw new ToolPayloadException('Accepted deferred result does not match its invocation.');
                }
                $result = $this->acceptedResults->read($deferredId, $reference);
                $call = $this->payloads->read($invocation);
                if (!$call instanceof ExecuteToolCall || !$this->fence->matchesResult($result, $call)) {
                    throw new ToolPayloadException('Accepted deferred result does not match its frozen invocation.');
                }
                $errors = $this->validator->validate($reference);
                if (0 !== \count($errors)) {
                    throw new ValidationFailedException($reference, $errors);
                }
                $serialized = $this->serializer->serialize($reference, 'json');
                $updated = $this->getEntityManager()->getConnection()->executeStatement(
                    'UPDATE tool_deferred_completion SET accepted_result_reference_json = :accepted, updated_at = :now WHERE deferred_id = :deferred_id AND status = :pending AND accepted_result_reference_json IS NULL',
                    [
                        'accepted' => $serialized,
                        'now' => Clock::get()->now()->format('Y-m-d H:i:s'),
                        'deferred_id' => $deferredId,
                        'pending' => 'pending',
                    ],
                );
                $managed = $this->findOneBy(['deferredId' => $deferredId]);
                if ($managed instanceof DeferredToolCompletion) {
                    $this->getEntityManager()->detach($managed);
                }
                if ($updated > 0) {
                    return $reference;
                }
                $fresh = $this->findFreshOneBy(['deferredId' => $deferredId]);
                if (!$fresh instanceof DeferredToolCompletion || 'pending' !== $fresh->status) {
                    throw new ToolPayloadException('Deferred registration cannot accept a terminal result.');
                }

                return $this->pendingAcceptedResultReference($fresh)
                    ?? throw new ToolPayloadException('Deferred registration lost its accepted terminal result.');
            },
        );
    }

    public function listPendingDeferredIdsForRun(string $runId): array
    {
        return $this->locks->synchronized($runId, function () use ($runId): array {
            $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT deferred_id FROM tool_deferred_completion WHERE run_id = :run_id AND (status = :pending OR continuation_required = 1) ORDER BY id ASC',
                [
                    'run_id' => $runId,
                    'pending' => 'pending',
                ],
            );

            return array_values(array_map(static fn (mixed $id): string => (string) $id, $rows));
        });
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

    private function registerLocked(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $existing = $this->findFreshOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if ($existing instanceof DeferredToolCompletion) {
            return $this->toCorrelation($existing);
        }

        $call = $this->batches->loadCall($correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->toolCallId);
        $callReference = $this->batches->loadCallReference($correlation->runId, $correlation->turnNo, $correlation->stepId, $correlation->toolCallId);
        $schedule = $this->batches->loadSchedule($correlation->runId, $correlation->turnNo, $correlation->stepId);
        $storedCall = null === $callReference ? null : $this->payloads->read($callReference);
        if (null === $call || null === $callReference || 'call' !== $callReference->kind || !$storedCall instanceof ExecuteToolCall || null === $schedule || !isset($schedule->inFlight[$correlation->toolCallId]) || isset($schedule->completedIds[$correlation->toolCallId]) || !$this->fence->matchesCorrelation($correlation, $call) || !$this->fence->sameInvocation($call, $storedCall)) {
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
                'invocation_reference_json' => $this->serializer->serialize($callReference, 'json'),
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

    private function pendingInvocationReference(DeferredToolCompletion $entity): ?ToolPayloadReferenceDTO
    {
        if ('pending' !== $entity->status) {
            return null;
        }
        if (null === $entity->invocationReferenceJson || '' === $entity->invocationReferenceJson) {
            throw new ToolPayloadException('Pending deferred registration is missing its invocation reference.');
        }
        $reference = $this->serializer->deserialize($entity->invocationReferenceJson, ToolPayloadReferenceDTO::class, 'json');
        $errors = $this->validator->validate($reference);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($reference, $errors);
        }
        $identity = [$reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex, $reference->toolName];
        $storedIdentity = [$entity->runId, $entity->turnNo, $entity->stepId, $entity->toolCallId, $entity->attempt, $entity->idempotencyKey, $entity->orderIndex, $entity->toolName];
        if ('call' !== $reference->kind || $identity !== $storedIdentity) {
            throw new ToolPayloadException('Deferred invocation reference does not match its index.');
        }

        return $reference;
    }

    private function pendingAcceptedResultReference(DeferredToolCompletion $entity): ?ToolPayloadReferenceDTO
    {
        if (('pending' !== $entity->status && !$entity->continuationRequired) || null === $entity->acceptedResultReferenceJson || '' === $entity->acceptedResultReferenceJson) {
            return null;
        }
        $reference = $this->serializer->deserialize($entity->acceptedResultReferenceJson, ToolPayloadReferenceDTO::class, 'json');
        $errors = $this->validator->validate($reference);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($reference, $errors);
        }
        $identity = [$reference->runId, $reference->turnNo, $reference->stepId, $reference->toolCallId, $reference->attempt, $reference->idempotencyKey, $reference->orderIndex, $reference->toolName];
        $storedIdentity = [$entity->runId, $entity->turnNo, $entity->stepId, $entity->toolCallId, $entity->attempt, $entity->idempotencyKey, $entity->orderIndex, $entity->toolName];
        if ('result' !== $reference->kind || $identity !== $storedIdentity) {
            throw new ToolPayloadException('Accepted deferred result reference does not match its index.');
        }

        return $reference;
    }
}
