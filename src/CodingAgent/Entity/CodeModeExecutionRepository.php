<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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

    public function register(string $identity, string $runId, string $toolCallId, string $reference): void
    {
        $this->getEntityManager()->getConnection()->insert('code_mode_execution', ['identity' => $identity, 'run_id' => $runId, 'tool_call_id' => $toolCallId, 'call_reference' => $reference, 'ready' => 0, 'detached' => 0, 'settled' => 0]);
    }

    public function ready(string $identity): void
    {
        $this->getEntityManager()->getConnection()->update('code_mode_execution', ['ready' => 1], ['identity' => $identity, 'settled' => 0]);
    }

    public function freezeRaw(string $identity, string $reference): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET raw_reference = ? WHERE identity = ? AND raw_reference IS NULL', [$reference, $identity]);
    }

    public function freezeResult(string $identity, string $reference): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('UPDATE code_mode_execution SET result_reference = ? WHERE identity = ? AND result_reference IS NULL', [$reference, $identity]);
    }

    public function detach(string $identity): void
    {
        $this->getEntityManager()->getConnection()->update('code_mode_execution', ['detached' => 1], ['identity' => $identity, 'settled' => 0]);
    }

    public function settle(string $identity): void
    {
        $this->getEntityManager()->getConnection()->update('code_mode_execution', ['settled' => 1], ['identity' => $identity]);
    }
}
