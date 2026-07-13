<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;

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
            return $this->toCorrelation($existing);
        }

        $entity = new DeferredToolCompletion();
        $entity->deferredId = $correlation->deferredId;
        $entity->runId = $correlation->runId;
        $entity->turnNo = $correlation->turnNo;
        $entity->stepId = $correlation->stepId;
        $entity->attempt = $correlation->attempt;
        $entity->idempotencyKey = $correlation->idempotencyKey;
        $entity->toolCallId = $correlation->toolCallId;
        $entity->toolName = $correlation->toolName;
        $entity->arguments = json_encode($correlation->arguments, \JSON_THROW_ON_ERROR);
        $entity->orderIndex = $correlation->orderIndex;
        $entity->toolIdempotencyKey = $correlation->toolIdempotencyKey;
        $entity->mode = $correlation->mode;
        $entity->timeoutSeconds = $correlation->timeoutSeconds;
        $entity->maxParallelism = $correlation->maxParallelism;
        $entity->assistantMessage = null !== $correlation->assistantMessage
            ? json_encode($correlation->assistantMessage, \JSON_THROW_ON_ERROR)
            : null;
        $entity->argSchema = null !== $correlation->argSchema
            ? json_encode($correlation->argSchema, \JSON_THROW_ON_ERROR)
            : null;
        $entity->toolsRef = $correlation->toolsRef;
        $entity->status = 'pending';

        $em = $this->getEntityManager();
        $em->persist($entity);
        $em->flush();

        return $this->toCorrelation($entity);
    }

    public function findPendingByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        $entity = $this->findOneBy([
            'runId' => $runId,
            'toolCallId' => $toolCallId,
        ]);

        if (!$entity instanceof DeferredToolCompletion) {
            return null;
        }

        if ('completed' === $entity->status) {
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
        $entity = $this->findOneBy(['deferredId' => $deferredId]);

        return $entity instanceof DeferredToolCompletion ? $entity->status : null;
    }

    public function tryBeginCompletion(string $deferredId): bool
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        $updated = $conn->executeStatement(
            'UPDATE deferred_tool_completion SET status = :completing, updated_at = :now WHERE deferred_id = :deferred_id AND status = :pending',
            [
                'completing' => 'completing',
                'pending' => 'pending',
                'deferred_id' => $deferredId,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        if ($updated > 0) {
            $em->clear();

            return true;
        }

        return false;
    }

    public function markCompleted(string $deferredId): void
    {
        $entity = $this->findOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            return;
        }

        $entity->status = 'completed';
        $this->getEntityManager()->flush();
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
        );
    }
}
