<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;
use Psr\Log\LoggerInterface;

final readonly class DbalRunSequenceAllocator implements RunSequenceAllocatorInterface
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function allocateNext(string $runId, ?callable $bootstrapMaxSeq = null): int
    {
        $block = $this->allocateBlock($runId, 1, $bootstrapMaxSeq);

        return $block[0];
    }

    public function allocateBlock(string $runId, int $count, ?callable $bootstrapMaxSeq = null): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('allocateBlock count must be >= 1.');
        }

        $this->connection->beginTransaction();

        try {
            $platform = $this->connection->getDatabasePlatform();
            $existing = $this->selectLastSeqForUpdate($runId, $platform);

            if (null === $existing) {
                $bootstrapMax = 0;
                if (null !== $bootstrapMaxSeq) {
                    $bootstrapMax = max(0, (int) $bootstrapMaxSeq());
                }

                $start = $bootstrapMax + 1;
                $end = $bootstrapMax + $count;
                $this->insertRow($runId, $end, $platform);

                $this->connection->commit();

                return range($start, $end);
            }

            $start = $existing + 1;
            $end = $existing + $count;
            $this->updateLastSeq($runId, $end, $platform);
            $this->connection->commit();

            return range($start, $end);
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    private function selectLastSeqForUpdate(string $runId, AbstractPlatform $platform): ?int
    {
        $sql = 'SELECT last_seq FROM hatfield_run_sequence WHERE run_id = ?';
        if ($platform instanceof PostgreSQLPlatform) {
            $sql .= ' FOR UPDATE';
        }

        $value = $this->connection->fetchOne($sql, [$runId]);
        if (false === $value || null === $value) {
            return null;
        }

        return (int) $value;
    }

    private function insertRow(string $runId, int $lastSeq, AbstractPlatform $platform): void
    {
        $this->connection->insert('hatfield_run_sequence', [
            'run_id' => $runId,
            'last_seq' => $lastSeq,
        ]);
    }

    private function updateLastSeq(string $runId, int $lastSeq, AbstractPlatform $platform): void
    {
        $updated = $this->connection->update('hatfield_run_sequence', [
            'last_seq' => $lastSeq,
        ], [
            'run_id' => $runId,
        ]);

        if (0 === $updated) {
            $this->logger->error('run_sequence.update_missing_row', [
                'run_id' => $runId,
                'component' => 'session.sequence_allocator',
                'event_type' => 'run_sequence.update_missing_row',
            ]);

            throw new \RuntimeException(\sprintf('Sequence row missing for run "%s" during allocation.', $runId));
        }
    }
}
