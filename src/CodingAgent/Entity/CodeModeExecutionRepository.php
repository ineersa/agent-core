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

    public function register(string $identity, string $runId, string $toolCallId): void
    {
        $this->getEntityManager()->getConnection()->insert('code_mode_execution', ['identity' => $identity, 'run_id' => $runId, 'tool_call_id' => $toolCallId, 'ready' => 0]);
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

    public function completionInterruption(string $identity): ?CodeModeInterruptionReasonEnum
    {
        if (!str_starts_with($identity, 'background-')) {
            return null;
        }
        $reason = $this->getEntityManager()->getConnection()->fetchOne('SELECT n.interruption_reason FROM code_mode_execution n JOIN tool_deferred_completion d ON d.deferred_id = n.identity WHERE n.identity = ? AND NOT (n.interruption_reason IN (?, ?) AND d.accepted_result_reference_json IS NOT NULL)', [substr($identity, \strlen('background-')), CodeModeInterruptionReasonEnum::ControllerRestarted->value, CodeModeInterruptionReasonEnum::ControllerShutdown->value]);

        return \is_string($reason) ? CodeModeInterruptionReasonEnum::from($reason) : null;
    }

    /** @return list<string> */
    public function activeIdentities(string $runId): array
    {
        $sql = "SELECT n.identity FROM code_mode_execution n JOIN tool_deferred_completion d ON d.deferred_id = n.identity WHERE n.run_id = ? AND (n.interruption_reason IS NULL OR n.interruption_reason IN (?, ?)) AND (d.status = 'pending' OR EXISTS (SELECT 1 FROM background_completion_receipt r WHERE r.identity = 'background-' || n.identity AND r.disposition IN ('pending', 'forwarding')))";

        return $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [$runId, CodeModeInterruptionReasonEnum::ControllerRestarted->value, CodeModeInterruptionReasonEnum::ControllerShutdown->value]);
    }

    public function freezeRaw(string $identity, string $reference): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET raw_reference = ? WHERE identity = ? AND raw_reference IS NULL', [$reference, $identity]);
    }

    /** @return list<string> Includes interrupted rows whose recovery send previously failed. */
    public function pendingControllerRecoveryIdentities(string $runId): array
    {
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT n.identity FROM code_mode_execution n JOIN tool_deferred_completion d ON d.deferred_id = n.identity WHERE n.run_id = ? AND d.status = 'pending' AND (n.interruption_reason IS NULL OR n.interruption_reason IN (?, ?))",
            [$runId, CodeModeInterruptionReasonEnum::ControllerRestarted->value, CodeModeInterruptionReasonEnum::ControllerShutdown->value],
        );
    }
}
