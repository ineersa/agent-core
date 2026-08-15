<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @extends ServiceEntityRepository<DeferredSubagentChild>
 */
final class DeferredSubagentChildRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct($registry, DeferredSubagentChild::class);
    }

    public function findByChildRunId(string $childRunId): ?DeferredSubagentChildProjectionDTO
    {
        $row = $this->findOneBy(['childRunId' => $childRunId]);

        return $row instanceof DeferredSubagentChild ? $this->toDto($row) : null;
    }

    /**
     * @return list<DeferredSubagentChildProjectionDTO>
     */
    public function findOrderedByBatchLifecycleId(string $batchLifecycleId): array
    {
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.batchLifecycleId = :batchLifecycleId')
            ->setParameter('batchLifecycleId', $batchLifecycleId)
            ->orderBy('c.batchIndex', 'ASC')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof DeferredSubagentChild) {
                $out[] = $this->toDto($row);
            }
        }

        return $out;
    }

    /**
     * @param list<array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, launchModel: string, launchReasoning: string}> $childIntents
     */
    public function insertReservedChildren(string $batchLifecycleId, array $childIntents, ?Connection $conn = null): void
    {
        $conn ??= $this->getEntityManager()->getConnection();
        $now = Clock::get()->now()->format('Y-m-d H:i:s');

        foreach ($childIntents as $intent) {
            try {
                $conn->insert('deferred_subagent_child', [
                    'batch_lifecycle_id' => $batchLifecycleId,
                    'batch_index' => $intent['batchIndex'],
                    'child_run_id' => $intent['childRunId'],
                    'artifact_id' => $intent['artifactId'],
                    'agent_name' => $intent['agentName'],
                    'task' => $intent['task'],
                    'launch_model' => $intent['launchModel'],
                    'launch_reasoning' => $intent['launchReasoning'],
                    'launch_status' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                    'child_event_cursor' => 0,
                    'projection_version' => 1,
                    'started_at' => null,
                    'terminal_completed_at' => null,
                    'terminal_status' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                $existing = $this->findOneBy([
                    'batchLifecycleId' => $batchLifecycleId,
                    'batchIndex' => $intent['batchIndex'],
                ]);
                if (!$existing instanceof DeferredSubagentChild) {
                    throw new \RuntimeException(\sprintf('Deferred subagent child reserve conflict for batch "%s" index %d but row missing.', $batchLifecycleId, $intent['batchIndex']));
                }
                $this->assertChildMatchesIntent($existing, $intent);
            }
        }
    }

    /**
     * @param array{batchIndex: int, childRunId: string, artifactId: string, agentName: string, task: string, launchModel: string, launchReasoning: string} $intent
     */
    public function assertChildMatchesIntent(DeferredSubagentChild $row, array $intent): void
    {
        if ($row->batchIndex !== $intent['batchIndex']
            || $row->childRunId !== $intent['childRunId']
            || $row->artifactId !== $intent['artifactId']) {
            throw new ToolCallException('Deferred subagent batch child identity does not match the deterministic launch for this tool call.', retryable: false);
        }

        if ($row->agentName !== $intent['agentName'] || $row->task !== $intent['task']) {
            throw new ToolCallException('Deferred subagent batch child was reserved for a different agent or task.', retryable: false);
        }

        if ($row->launchModel !== $intent['launchModel'] || $row->launchReasoning !== $intent['launchReasoning']) {
            throw new ToolCallException('Deferred subagent batch child was reserved with a different launch model or reasoning.', retryable: false);
        }
    }

    public function markChildLaunched(string $batchLifecycleId, int $batchIndex, \DateTimeImmutable $startedAt): void
    {
        $row = $this->requireChild($batchLifecycleId, $batchIndex);
        if (DeferredSubagentChildLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }
        if (DeferredSubagentChildLaunchStatusEnum::Failed === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSubagentChildLaunchStatusEnum::Launched;
        $row->startedAt = $row->startedAt ?? $startedAt;
        $this->getEntityManager()->flush();
    }

    public function markChildFailed(string $batchLifecycleId, int $batchIndex): void
    {
        $row = $this->requireChild($batchLifecycleId, $batchIndex);
        if (DeferredSubagentChildLaunchStatusEnum::Launched === $row->launchStatus) {
            return;
        }

        $row->launchStatus = DeferredSubagentChildLaunchStatusEnum::Failed;
        $this->getEntityManager()->flush();
    }

    public function findEntityByChildRunId(string $childRunId): ?DeferredSubagentChild
    {
        $row = $this->findOneBy(['childRunId' => $childRunId]);

        return $row instanceof DeferredSubagentChild ? $row : null;
    }

    public function findEntityByBatchLifecycleAndIndex(string $batchLifecycleId, int $batchIndex): ?DeferredSubagentChild
    {
        $row = $this->findOneBy([
            'batchLifecycleId' => $batchLifecycleId,
            'batchIndex' => $batchIndex,
        ]);

        return $row instanceof DeferredSubagentChild ? $row : null;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    public function decodeChildLifecycleProjection(?array $raw): ?DeferredChildRunLifecycleProjectionDTO
    {
        if (null === $raw || [] === $raw) {
            return null;
        }

        try {
            /** @var DeferredChildRunLifecycleProjectionDTO $projection */
            $projection = $this->serializer->denormalize($raw, DeferredChildRunLifecycleProjectionDTO::class);
        } catch (SerializerExceptionInterface|\TypeError|\ValueError $exception) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: %s', $exception->getMessage()), 0, $exception);
        }

        $violations = $this->validator->validate($projection);
        if ($violations->count() > 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: validation failed with %d violation(s).', $violations->count()), 0, new ValidationFailedException($projection, $violations));
        }

        return $projection;
    }

    private function requireChild(string $batchLifecycleId, int $batchIndex): DeferredSubagentChild
    {
        $row = $this->findOneBy([
            'batchLifecycleId' => $batchLifecycleId,
            'batchIndex' => $batchIndex,
        ]);

        if (!$row instanceof DeferredSubagentChild) {
            throw new \RuntimeException(\sprintf('Deferred subagent child missing for batch "%s" index %d.', $batchLifecycleId, $batchIndex));
        }

        return $row;
    }

    private function toDto(DeferredSubagentChild $row): DeferredSubagentChildProjectionDTO
    {
        return new DeferredSubagentChildProjectionDTO(
            batchLifecycleId: $row->batchLifecycleId,
            batchIndex: $row->batchIndex,
            childRunId: $row->childRunId,
            artifactId: $row->artifactId,
            agentName: $row->agentName,
            task: $row->task,
            launchModel: $row->launchModel,
            launchReasoning: $row->launchReasoning,
            launchStatus: $row->launchStatus,
            childEventCursor: $row->childEventCursor,
            childLifecycleProjection: $this->decodeChildLifecycleProjection($row->childLifecycleProjection),
            startedAt: $row->startedAt,
            terminalCompletedAt: $row->terminalCompletedAt,
            terminalStatus: $row->terminalStatus,
            projectionVersion: $row->projectionVersion,
        );
    }
}
