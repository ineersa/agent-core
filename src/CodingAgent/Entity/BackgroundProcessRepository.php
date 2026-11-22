<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine repository for BackgroundProcess entities.
 *
 * @extends ServiceEntityRepository<BackgroundProcess>
 */
final class BackgroundProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackgroundProcess::class);
    }

    /** @return array{workspace: string, startedAtNs: int, fenced: bool, standaloneTurn: ?int, policy: array{explicitTimeout: ?int, callerTimeout: ?int, retainOutput: bool, attachmentOwner: string}}|null */
    public function codeModeLaunch(string $runId, string $id): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT code_mode_launch, finished_at FROM background_process WHERE session_id = ? AND code_mode_id = ?', [$runId, $id]);
        if (false === $row) {
            return null;
        }
        $launch = json_decode($row['code_mode_launch'], true, flags: \JSON_THROW_ON_ERROR);

        return $launch + ['fenced' => null !== $row['finished_at']];
    }

    /** @return list<string> */
    public function codeModeIds(string $runId): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn('SELECT code_mode_id FROM background_process WHERE session_id = ? AND code_mode_id IS NOT NULL ORDER BY id', [$runId]);
    }

    /** @return list<string> */
    public function pendingCodeModeIds(string $runId): array
    {
        // Drive polling from the active deferred index, not the retained process
        // catalog. A fenced launch still needs its pending outcome settled.
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(<<<'SQL'
            SELECT invocation.deferred_id
            FROM tool_deferred_completion invocation
            WHERE invocation.tool_name = 'code_mode' AND invocation.status = 'pending' AND invocation.run_id = :run
                AND EXISTS (
                    SELECT 1 FROM background_process process
                    WHERE process.code_mode_id = invocation.deferred_id AND process.session_id = invocation.run_id
                )
            UNION
            SELECT process.code_mode_id FROM background_process process
            WHERE process.session_id = :run AND process.code_mode_id IS NOT NULL
                AND process.finished_at IS NULL AND json_extract(process.code_mode_launch, '$.standaloneTurn') IS NOT NULL
            SQL, ['run' => $runId]);
    }

    public function fenceCodeModeLaunch(string $runId, string $id): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE background_process SET finished_at = CURRENT_TIMESTAMP WHERE session_id = ? AND code_mode_id = ? AND finished_at IS NULL', [$runId, $id]);
    }

    public function removeCodeModeLaunch(string $runId, string $id): void
    {
        $this->getEntityManager()->getConnection()->delete('background_process', ['session_id' => $runId, 'code_mode_id' => $id]);
    }

    /** @return list<array<string, mixed>> */
    public function findOwnedBackgroundWork(string $rootRunId, ?int $observedRecordId): array
    {
        // Finished history is not loaded into the picker. A pending UI action
        // may additionally observe its exact retained row until native settlement.
        // child_run_id is unique, including resume rebinds. Resolve ownership
        // and its attribution in this statement without duplicating process rows.
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT process.id, process.pid, process.command, process.session_id,
                process.status, process.finished_at, process.started_at,
                child.agent_name AS owner_name, child.artifact_id AS owner_artifact_id
            FROM background_process process
            LEFT JOIN deferred_subagent_child child ON child.child_run_id = process.session_id
            LEFT JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
            WHERE process.backgrounded_at IS NOT NULL
                AND (process.finished_at IS NULL OR process.id = :observed)
                AND (process.session_id = :root OR batch.parent_run_id = :root)
            ORDER BY process.id
            SQL, ['root' => $rootRunId, 'observed' => $observedRecordId]);
    }
}
