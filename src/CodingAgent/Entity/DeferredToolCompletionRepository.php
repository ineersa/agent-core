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
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @extends ServiceEntityRepository<DeferredToolCompletion>
 */
final class DeferredToolCompletionRepository extends ServiceEntityRepository implements DeferredToolCompletionRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly SerializerInterface $serializer,
    ) {
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
        $computedFingerprint = ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation);
        if (null !== $correlation->envelopeFingerprint && $correlation->envelopeFingerprint !== $computedFingerprint) {
            throw new \InvalidArgumentException('Envelope fingerprint must match the immutable execution envelope.');
        }
        $fingerprint = $correlation->envelopeFingerprint ?? $computedFingerprint;
        $ownerReference = null === $correlation->executionOwnerReference
            ? null
            : $this->serializeOwnerReference($correlation->executionOwnerReference);
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
                'delivery_fence_json' => null === $correlation->deliveryFence ? null : $this->serializer->serialize($correlation->deliveryFence, 'json'),
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
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $this->toCorrelation($entity) : null;
    }

    public function status(string $deferredId): ?string
    {
        $entity = $this->findFreshOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $entity->status : null;
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
        $action->assertCurrentBindingMatches($current);

        if ($current->callerDisposition === $action->callerDisposition
            && $current->executionObservation === $action->executionObservation) {
            if (null !== $action->acceptedCompletionId) {
                if ($current->acceptedCompletionId === $action->acceptedCompletionId
                    && $current->acceptedResultFingerprint === $action->acceptedResultFingerprint
                    && 'completed' === $entity->status) {
                    return;
                }
                if (null !== $current->acceptedCompletionId
                    && ($current->acceptedCompletionId !== $action->acceptedCompletionId
                        || $current->acceptedResultFingerprint !== $action->acceptedResultFingerprint)) {
                    throw new \RuntimeException(\sprintf('Conflicting accepted completion for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
                }
            } else {
                return;
            }
        }

        $next = $current->withLifecycle(
            $action->callerDisposition,
            $action->executionObservation,
            $action->acceptedCompletionId,
            $action->acceptedResultFingerprint,
            $action->deliveryFence,
        );
        $markCompleted = null !== $next->acceptedCompletionId
            && (ToolInvocationExecutionObservationEnum::Terminal === $next->executionObservation
                || ToolInvocationExecutionObservationEnum::Unknown === $next->executionObservation);
        $updated = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE deferred_tool_completion
             SET caller_disposition = :caller_disposition,
                 execution_observation = :execution_observation,
                 accepted_completion_id = :accepted_completion_id,
                 accepted_result_fingerprint = :accepted_result_fingerprint,
                 delivery_fence_json = :delivery_fence_json,
                 status = :status,
                 updated_at = :now
             WHERE deferred_id = :deferred_id
               AND caller_disposition = :expected_disposition
               AND execution_observation = :expected_observation
               AND (
                    (:expected_completion_id IS NULL AND accepted_completion_id IS NULL)
                    OR accepted_completion_id = :expected_completion_id
               )
               AND (
                    (:expected_result_fingerprint IS NULL AND accepted_result_fingerprint IS NULL)
                    OR accepted_result_fingerprint = :expected_result_fingerprint
               )',
            [
                'caller_disposition' => $next->callerDisposition->value,
                'execution_observation' => $next->executionObservation->value,
                'accepted_completion_id' => $next->acceptedCompletionId,
                'accepted_result_fingerprint' => $next->acceptedResultFingerprint,
                'delivery_fence_json' => null === $next->deliveryFence ? null : $this->serializer->serialize($next->deliveryFence, 'json'),
                'status' => $markCompleted ? 'completed' : $entity->status,
                'now' => Clock::get()->now()->format('Y-m-d H:i:s'),
                'deferred_id' => $current->deferredId,
                'expected_disposition' => $current->callerDisposition->value,
                'expected_observation' => $current->executionObservation->value,
                'expected_completion_id' => $current->acceptedCompletionId,
                'expected_result_fingerprint' => $current->acceptedResultFingerprint,
            ],
        );

        if (0 === $updated) {
            $refreshed = $this->findFreshOneBy(['deferredId' => $current->deferredId]);
            if (!$refreshed instanceof DeferredToolCompletion) {
                throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection disappeared for "%s".', $current->deferredId));
            }
            $refreshedCorrelation = $this->toCorrelation($refreshed);
            $action->assertCurrentBindingMatches($refreshedCorrelation);
            if ($refreshedCorrelation->callerDisposition === $action->callerDisposition
                && $refreshedCorrelation->executionObservation === $action->executionObservation) {
                if (null === $action->acceptedCompletionId
                    || ($refreshedCorrelation->acceptedCompletionId === $action->acceptedCompletionId
                        && $refreshedCorrelation->acceptedResultFingerprint === $action->acceptedResultFingerprint
                        && 'completed' === $refreshed->status)) {
                    return;
                }
            }

            throw new \RuntimeException(\sprintf('Deferred tool lifecycle projection raced for run "%s" tool call "%s".', $expected->runId, $expected->toolCallId));
        }

        // DBAL write bypasses the identity map; detach any stale managed row so later find* reads fresh disposition/observation.
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
     * Registration conflict lookups may keep plain findOneBy() for immutable
     * insert fields. Status and lifecycle-bearing reads must overwrite
     * identity-map copies after DBAL updates.
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
            $ownerReference = $this->deserializeOwnerReference(
                $entity->executionOwnerReferenceJson,
                $entity->deferredId,
            );
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
            deliveryFence: null === $entity->deliveryFenceJson ? null : $this->serializer->deserialize($entity->deliveryFenceJson, \Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO::class, 'json'),
            envelopeFingerprint: $entity->envelopeFingerprint,
            callerDisposition: ToolInvocationCallerDispositionEnum::from($entity->callerDisposition),
            executionObservation: ToolInvocationExecutionObservationEnum::from($entity->executionObservation),
            acceptedCompletionId: $entity->acceptedCompletionId,
            acceptedResultFingerprint: $entity->acceptedResultFingerprint,
        );
    }

    private function serializeOwnerReference(ToolInvocationOwnerReference $ownerReference): string
    {
        return $this->serializer->serialize(
            $ownerReference,
            'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );
    }

    private function deserializeOwnerReference(string $json, string $deferredId): ToolInvocationOwnerReference
    {
        try {
            /** @var ToolInvocationOwnerReference $ownerReference */
            $ownerReference = $this->serializer->deserialize(
                $json,
                ToolInvocationOwnerReference::class,
                'json',
            );
        } catch (SerializerExceptionInterface|\TypeError|\ValueError|\InvalidArgumentException|\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Deferred tool owner reference is corrupt for "%s".', $deferredId), 0, $exception);
        }

        return $ownerReference;
    }
}
