<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
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
            return $this->toCorrelation($existing);
        }

        $now = Clock::get()->now()->format('Y-m-d H:i:s');

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

        $entity = $this->findOneBy(['deferredId' => $deferredId]);
        if (!$entity instanceof DeferredToolCompletion) {
            return;
        }

        if ('completed' === $entity->status) {
            return;
        }

        $entity->status = 'completed';
        $em->flush();
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
            return $this->toCorrelation($byRunTool);
        }

        $byDeferredId = $this->findOneBy(['deferredId' => $correlation->deferredId]);
        if ($byDeferredId instanceof DeferredToolCompletion) {
            if ($byDeferredId->runId !== $correlation->runId || $byDeferredId->toolCallId !== $correlation->toolCallId) {
                throw new \RuntimeException(\sprintf('Deferred id "%s" is already registered for run "%s" tool call "%s"; cannot register run "%s" tool call "%s".', $correlation->deferredId, $byDeferredId->runId, $byDeferredId->toolCallId, $correlation->runId, $correlation->toolCallId));
            }

            return $this->toCorrelation($byDeferredId);
        }

        throw $exception;
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
