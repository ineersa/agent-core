<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentChildProjectionDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredChildRunLifecycleProjectionDTO;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @extends ServiceEntityRepository<DeferredSubagentChild>
 */
final class DeferredSubagentChildRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly SerializerInterface $serializer,
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
                    $byChildRun = $this->findEntityByChildRunId($intent['childRunId']);
                    if ($byChildRun instanceof DeferredSubagentChild) {
                        // Resume rebinds an existing terminal child onto a new batch.
                        $this->rebindExistingChildToResumeBatch(
                            batchLifecycleId: $batchLifecycleId,
                            batchIndex: $intent['batchIndex'],
                            childRunId: $intent['childRunId'],
                            artifactId: $intent['artifactId'],
                            agentName: $intent['agentName'],
                            task: $intent['task'],
                            launchModel: $intent['launchModel'],
                            launchReasoning: $intent['launchReasoning'],
                            conn: $conn,
                            existing: $byChildRun,
                        );

                        continue;
                    }

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

    public function findEntityByChildRunId(string $childRunId): ?DeferredSubagentChild
    {
        $row = $this->findOneBy(['childRunId' => $childRunId]);

        return $row instanceof DeferredSubagentChild ? $row : null;
    }

    /**
     * Rebind an existing child run onto a new resume deferred batch, or insert if absent.
     *
     * Keeps the event cursor so only post-resume events are observed, clears prior
     * terminal markers, and resets the lifecycle projection to Running.
     */
    public function rebindExistingChildToResumeBatch(
        string $batchLifecycleId,
        int $batchIndex,
        string $childRunId,
        string $artifactId,
        string $agentName,
        string $task,
        string $launchModel,
        string $launchReasoning,
        ?Connection $conn = null,
        ?DeferredSubagentChild $existing = null,
    ): void {
        $now = Clock::get()->now();
        $conn ??= $this->getEntityManager()->getConnection();
        $existing ??= $this->findEntityByChildRunId($childRunId);
        $cursor = null === $existing ? 0 : $existing->childEventCursor;
        $previous = null !== $existing ? $this->decodeChildLifecycleProjection($existing->childLifecycleProjection) : null;
        $projection = new DeferredChildRunLifecycleProjectionDTO(
            childStatus: RunStatus::Running,
            childTurnNo: 0,
            lastCommittedSeq: $cursor,
            model: $launchModel,
            reasoning: $launchReasoning,
            latestInputTokens: null === $previous ? 0 : $previous->latestInputTokens,
            contextWindow: $previous?->contextWindow,
        );

        $projectionJson = $this->serializer->serialize(
            $projection,
            'json',
            [AbstractObjectNormalizer::SKIP_NULL_VALUES => true],
        );

        if ($existing instanceof DeferredSubagentChild) {
            $conn->update('deferred_subagent_child', [
                'batch_lifecycle_id' => $batchLifecycleId,
                'batch_index' => $batchIndex,
                'artifact_id' => $artifactId,
                'agent_name' => $agentName,
                'task' => $task,
                'launch_model' => $launchModel,
                'launch_reasoning' => $launchReasoning,
                'launch_status' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
                'child_lifecycle_projection' => $projectionJson,
                'terminal_completed_at' => null,
                'terminal_status' => null,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ], [
                'child_run_id' => $childRunId,
            ]);

            return;
        }

        $conn->insert('deferred_subagent_child', [
            'batch_lifecycle_id' => $batchLifecycleId,
            'batch_index' => $batchIndex,
            'child_run_id' => $childRunId,
            'artifact_id' => $artifactId,
            'agent_name' => $agentName,
            'task' => $task,
            'launch_model' => $launchModel,
            'launch_reasoning' => $launchReasoning,
            'launch_status' => DeferredSubagentChildLaunchStatusEnum::Reserved->value,
            'child_event_cursor' => $cursor,
            'child_lifecycle_projection' => $projectionJson,
            'projection_version' => 1,
            'started_at' => null,
            'terminal_completed_at' => null,
            'terminal_status' => null,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
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
            $projection = $this->serializer->deserialize(
                json_encode($raw, \JSON_THROW_ON_ERROR),
                DeferredChildRunLifecycleProjectionDTO::class,
                'json',
            );
        } catch (SerializerExceptionInterface|\TypeError|\ValueError|\JsonException $exception) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: %s', $exception->getMessage()), 0, $exception);
        }

        $violations = $this->validator->validate($projection);
        if ($violations->count() > 0) {
            throw new \InvalidArgumentException(\sprintf('Invalid deferred child lifecycle projection: validation failed with %d violation(s).', $violations->count()), 0, new ValidationFailedException($projection, $violations));
        }

        return $projection;
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
