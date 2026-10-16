<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Symfony\Component\Clock\Clock;

/**
 * @extends ServiceEntityRepository<DeferredToolCompletion>
 */
final class DeferredToolCompletionRepository extends ServiceEntityRepository implements DeferredToolCompletionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeferredToolCompletion::class);
    }

    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $existing = $this->findOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if ($existing instanceof DeferredToolCompletion) {
            $current = $this->toCorrelation($existing);
            $current->assertEnvelopeMatches($correlation);

            return $current;
        }

        $now = Clock::get()->now()->format('Y-m-d H:i:s');
        $fingerprint = $correlation->envelopeFingerprint ?? ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation);
        $ownerReference = null === $correlation->executionOwnerReference
            ? null
            : json_encode($correlation->executionOwnerReference->toArray(), \JSON_THROW_ON_ERROR);
        $deadlineMetadata = null === $correlation->deadlineMetadata
            ? null
            : json_encode($correlation->deadlineMetadata, \JSON_THROW_ON_ERROR);

        try {
            $this->getEntityManager()->getConnection()->insert('deferred_tool_completion', [
                'deferred_id' => $correlation->deferredId,
                'run_id' => $correlation->runId,
                'turn_no' => $correlation->turnNo,
                'step_id' => $correlation->stepId,
                'attempt' => $correlation->attempt,
                'idempotency_key' => $correlation->idempotencyKey,
                'tool_call_id' => $correlation->toolCallId,
                'tool_name' => $correlation->toolName,
                'arguments' => json_encode($correlation->arguments, \JSON_THROW_ON_ERROR),
                'order_index' => $correlation->orderIndex,
                'tool_idempotency_key' => $correlation->toolIdempotencyKey,
                'mode' => $correlation->mode,
                'timeout_seconds' => $correlation->timeoutSeconds,
                'max_parallelism' => $correlation->maxParallelism,
                'assistant_message' => null !== $correlation->assistantMessage
                    ? json_encode($correlation->assistantMessage, \JSON_THROW_ON_ERROR)
                    : null,
                'arg_schema' => null !== $correlation->argSchema
                    ? json_encode($correlation->argSchema, \JSON_THROW_ON_ERROR)
                    : null,
                'tools_ref' => $correlation->toolsRef,
                'invocation_id' => $correlation->invocationId,
                'controller_run_id' => $correlation->controllerRunId,
                'execution_owner_kind' => $correlation->executionOwnerKind,
                'execution_owner_reference_json' => $ownerReference,
                'original_execution_mode' => $correlation->originalExecutionMode,
                'deadline_metadata_json' => $deadlineMetadata,
                'origin_wake_generation' => $correlation->originWakeGeneration,
                'root_wake_generation' => $correlation->rootWakeGeneration,
                'envelope_fingerprint' => $fingerprint,
                'caller_disposition' => $correlation->callerDisposition->value,
                'execution_observation' => $correlation->executionObservation->value,
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

    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        $entity = $this->findFreshOneBy([
            'runId' => $runId,
            'toolCallId' => $toolCallId,
        ]);

        if (!$entity instanceof DeferredToolCompletion) {
            return null;
        }

        return $this->toCorrelation($entity);
    }

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation
    {
        $entity = $this->findOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $this->toCorrelation($entity) : null;
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
            'UPDATE deferred_tool_completion SET status = :completed, updated_at = :now WHERE deferred_id = :deferred_id AND status = :pending',
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

    public function applyLifecycleProjection(RecordToolInvocationLifecycleDTO $action): void
    {
        $expected = $action->expectedCorrelation;
        $entity = $this->findFreshOneBy([
            'runId' => $expected->runId,
            'toolCallId' => $expected->toolCallId,
        ]);
        if (!$entity instanceof DeferredToolCompletion) {
            throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection missing for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
        }

        $current = $this->toCorrelation($entity);
        $current->assertEnvelopeMatches($expected);

        if ($current->callerDisposition === $action->callerDisposition
            && $current->executionObservation === $action->executionObservation) {
            return;
        }

        $next = $current->withLifecycle($action->callerDisposition, $action->executionObservation);
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE deferred_tool_completion
             SET caller_disposition = :caller_disposition,
                 execution_observation = :execution_observation,
                 updated_at = :now
             WHERE deferred_id = :deferred_id
               AND caller_disposition = :expected_disposition
               AND execution_observation = :expected_observation',
            [
                'caller_disposition' => $next->callerDisposition->value,
                'execution_observation' => $next->executionObservation->value,
                'now' => Clock::get()->now()->format('Y-m-d H:i:s'),
                'deferred_id' => $current->deferredId,
                'expected_disposition' => $current->callerDisposition->value,
                'expected_observation' => $current->executionObservation->value,
            ],
        );

        if (0 === $updated) {
            $refreshed = $this->findFreshOneBy(['deferredId' => $current->deferredId]);
            if (!$refreshed instanceof DeferredToolCompletion) {
                throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection disappeared for "%s".', $current->deferredId));
            }
            $refreshedCorrelation = $this->toCorrelation($refreshed);
            if ($refreshedCorrelation->callerDisposition === $action->callerDisposition
                && $refreshedCorrelation->executionObservation === $action->executionObservation) {
                return;
            }

            throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection raced for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
        }

        $managed = $this->findOneBy(['deferredId' => $current->deferredId]);
        if ($managed instanceof DeferredToolCompletion) {
            $this->getEntityManager()->detach($managed);
        }
    }

    private function resolveRegistrationConflict(
        DeferredToolCompletionCorrelation $correlation,
        UniqueConstraintViolationException $exception,
    ): DeferredToolCompletionCorrelation {
        $byRunTool = $this->findOneBy([
            'runId' => $correlation->runId,
            'toolCallId' => $correlation->toolCallId,
        ]);

        if ($byRunTool instanceof DeferredToolCompletion) {
            $current = $this->toCorrelation($byRunTool);
            $current->assertEnvelopeMatches($correlation);

            return $current;
        }

        $byDeferredId = $this->findOneBy(['deferredId' => $correlation->deferredId]);
        if ($byDeferredId instanceof DeferredToolCompletion) {
            if ($byDeferredId->runId !== $correlation->runId || $byDeferredId->toolCallId !== $correlation->toolCallId) {
                throw new \RuntimeException(\sprintf('Deferred id "%s" is already registered for run "%s" tool call "%s"; cannot register run "%s" tool call "%s".', $correlation->deferredId, $byDeferredId->runId, $byDeferredId->toolCallId, $correlation->runId, $correlation->toolCallId));
            }

            $current = $this->toCorrelation($byDeferredId);
            $current->assertEnvelopeMatches($correlation);

            return $current;
        }

        throw $exception;
    }

    /**
     * Load one row with committed field values for mutable status decisions.
     *
     * Registration lookups keep plain findOneBy(): those fields are immutable
     * after insert. Status-bearing reads must overwrite identity-map copies.
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
        /** @var array<string, mixed> $arguments */
        $arguments = json_decode($entity->arguments, true, 512, \JSON_THROW_ON_ERROR);

        $assistantMessage = null;
        if (null !== $entity->assistantMessage && '' !== $entity->assistantMessage) {
            $assistantMessage = json_decode($entity->assistantMessage, true, 512, \JSON_THROW_ON_ERROR);
        }

        $argSchema = null;
        if (null !== $entity->argSchema && '' !== $entity->argSchema) {
            $argSchema = json_decode($entity->argSchema, true, 512, \JSON_THROW_ON_ERROR);
        }

        $ownerReference = null;
        if (null !== $entity->executionOwnerReferenceJson && '' !== $entity->executionOwnerReferenceJson) {
            $decodedOwner = json_decode($entity->executionOwnerReferenceJson, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decodedOwner)) {
                throw new \RuntimeException(\sprintf('Deferred tool owner reference is corrupt for "%s".', $entity->deferredId));
            }
            $ownerReference = ToolInvocationOwnerReference::fromArray($decodedOwner);
        }

        $deadlineMetadata = null;
        if (null !== $entity->deadlineMetadataJson && '' !== $entity->deadlineMetadataJson) {
            $deadlineMetadata = json_decode($entity->deadlineMetadataJson, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($deadlineMetadata)) {
                throw new \RuntimeException(\sprintf('Deferred tool deadline metadata is corrupt for "%s".', $entity->deferredId));
            }
        }

        return new DeferredToolCompletionCorrelation(
            deferredId: $entity->deferredId,
            runId: $entity->runId,
            turnNo: $entity->turnNo,
            stepId: $entity->stepId,
            attempt: $entity->attempt,
            idempotencyKey: $entity->idempotencyKey,
            toolCallId: $entity->toolCallId,
            toolName: $entity->toolName,
            arguments: \is_array($arguments) ? $arguments : [],
            orderIndex: $entity->orderIndex,
            toolIdempotencyKey: $entity->toolIdempotencyKey,
            mode: $entity->mode,
            timeoutSeconds: $entity->timeoutSeconds,
            maxParallelism: $entity->maxParallelism,
            assistantMessage: \is_array($assistantMessage) ? $assistantMessage : null,
            argSchema: \is_array($argSchema) ? $argSchema : null,
            toolsRef: $entity->toolsRef,
            invocationId: $entity->invocationId,
            controllerRunId: $entity->controllerRunId,
            executionOwnerKind: $entity->executionOwnerKind,
            executionOwnerReference: $ownerReference,
            originalExecutionMode: $entity->originalExecutionMode,
            deadlineMetadata: $deadlineMetadata,
            originWakeGeneration: $entity->originWakeGeneration,
            rootWakeGeneration: $entity->rootWakeGeneration,
            envelopeFingerprint: $entity->envelopeFingerprint,
            callerDisposition: ToolInvocationCallerDispositionEnum::from($entity->callerDisposition),
            executionObservation: ToolInvocationExecutionObservationEnum::from($entity->executionObservation),
        );
    }
}
