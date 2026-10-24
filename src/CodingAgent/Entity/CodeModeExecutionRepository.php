<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;

/** @extends ServiceEntityRepository<CodeModeExecution> */
final class CodeModeExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CodeModeExecution::class);
    }

    /** @return array<string, mixed>|null */
    public function invocation(string $runId, string $toolCallId): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM code_mode_execution WHERE run_id = ? AND tool_call_id = ?', [$runId, $toolCallId]);

        return false === $row ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function byIdentity(string $identity): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM code_mode_execution WHERE identity = ?', [$identity]);

        return false === $row ? null : $row;
    }

    public function register(string $identity, string $runId, string $toolCallId): void
    {
        $this->getEntityManager()->getConnection()->insert('code_mode_execution', ['identity' => $identity, 'run_id' => $runId, 'tool_call_id' => $toolCallId, 'ready' => 0]);
    }

    /** @return list<array<string, mixed>> */
    public function foregroundCandidates(string $runId): array
    {
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT n.identity, n.tool_call_id
            FROM code_mode_execution n
            JOIN tool_deferred_completion d ON d.deferred_id = n.identity
            JOIN run_operational_state state ON state.run_id = n.run_id
            WHERE n.run_id = ? AND n.ready = 1 AND n.interruption_reason IS NULL
                AND n.raw_reference IS NULL AND d.status = 'pending'
                AND d.acknowledged_at IS NULL AND d.accepted_result_reference_json IS NULL
                AND state.status = 'running'
            ORDER BY d.id
            SQL, [$runId]);
    }

    /** @return list<array<string, mixed>> */
    public function ownedBackgroundWork(string $rootRunId, ?string $observedIdentity): array
    {
        // Load live hosts plus only the exact terminal row a pending UI action
        // observes. Completed host history must not grow the picker snapshot.
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(<<<'SQL'
            SELECT n.*, d.accepted_result_reference_json, d.status AS deferred_status,
                child.agent_name AS owner_name, child.artifact_id AS owner_artifact_id
            FROM code_mode_execution n
            JOIN tool_deferred_completion d ON d.deferred_id = n.identity
            LEFT JOIN deferred_subagent_child child ON child.child_run_id = n.run_id
            LEFT JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
            WHERE d.acknowledged_at IS NOT NULL
                AND ((n.raw_reference IS NULL AND d.accepted_result_reference_json IS NULL AND d.status = 'pending') OR n.identity = :observed)
                AND (n.run_id = :root OR batch.parent_run_id = :root)
            ORDER BY d.id
            SQL, ['root' => $rootRunId, 'observed' => $observedIdentity]);
    }

    public function canStop(string $runId, string $identity, string $toolCallId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(<<<'SQL'
            SELECT n.identity FROM code_mode_execution n
            JOIN tool_deferred_completion d ON d.deferred_id = n.identity
            WHERE n.run_id = ? AND n.identity = ? AND n.tool_call_id = ?
                AND n.raw_reference IS NULL AND n.interruption_reason IS NULL
                AND d.acknowledged_at IS NOT NULL AND d.accepted_result_reference_json IS NULL AND d.status = 'pending'
            SQL, [$runId, $identity, $toolCallId]);
    }

    public function ready(string $identity): void
    {
        if (1 !== $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET ready = 1 WHERE identity = ? AND interruption_reason IS NULL', [$identity])) {
            throw new \RuntimeException('Code-mode host ownership ended before readiness.');
        }
    }

    public function isActive(string $identity): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne('SELECT identity FROM code_mode_execution WHERE identity = ? AND interruption_reason IS NULL', [$identity]);
    }

    public function interrupt(string $identity, CodeModeInterruptionReasonEnum $reason): void
    {
        // Explicit abandonment also fences a controller-recovery notice that is
        // accepted but not yet consumed. Controller loss cannot weaken that fence.
        $abandoned = \in_array($reason, [CodeModeInterruptionReasonEnum::Cancelled, CodeModeInterruptionReasonEnum::HistoryDiscarded], true);
        $eligible = $abandoned ? '(interruption_reason IS NULL OR interruption_reason IN (?, ?))' : 'interruption_reason IS NULL';
        $parameters = [$reason->value, $identity];
        if ($abandoned) {
            $parameters[] = CodeModeInterruptionReasonEnum::ControllerRestarted->value;
            $parameters[] = CodeModeInterruptionReasonEnum::ControllerShutdown->value;
        }
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET interruption_reason = ? WHERE identity = ? AND '.$eligible, $parameters);
    }

    public function interruption(string $runId, string $toolCallId): ?CodeModeInterruptionReasonEnum
    {
        $reason = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT interruption_reason FROM code_mode_execution WHERE run_id = ? AND tool_call_id = ?',
            [$runId, $toolCallId],
        );

        return \is_string($reason) && '' !== $reason ? CodeModeInterruptionReasonEnum::from($reason) : null;
    }

    /** @return list<string> Native host identities for one run, without delivery-policy joins. */
    public function identitiesForRun(string $runId): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT identity FROM code_mode_execution WHERE run_id = ? ORDER BY identity',
            [$runId],
        );
    }

    public function freezeRaw(string $identity, string $reference): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET raw_reference = ? WHERE identity = ? AND raw_reference IS NULL', [$reference, $identity]);
    }
}
