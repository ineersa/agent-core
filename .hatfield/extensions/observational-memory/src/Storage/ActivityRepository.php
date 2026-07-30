<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;

/**
 * Ephemeral per-run OM worker activity for the TUI status row.
 *
 * One row per run_id (PK). Writers upsert; clear is job_id-guarded so an older
 * job's finally cannot erase a newer job's stage. Readers hide rows older than
 * five minutes (worker crash without clear).
 */
final class ActivityRepository
{
    public const int STALE_AFTER_SECONDS = 300;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function upsert(
        string $runId,
        string $jobId,
        string $stage,
        int $currentTokens,
        ?int $targetTokens = null,
        ?string $updatedAt = null,
    ): void {
        if (!\in_array($stage, ['observer', 'reflector', 'dropper'], true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid OM activity stage "%s".', $stage));
        }
        if ($currentTokens < 0) {
            throw new \InvalidArgumentException('current_tokens must be non-negative.');
        }
        if (null !== $targetTokens && $targetTokens <= 0) {
            throw new \InvalidArgumentException('target_tokens must be positive when set.');
        }

        $updatedAt ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $this->connection->executeStatement(
            'INSERT INTO om_current_activity (run_id, job_id, stage, current_tokens, target_tokens, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(run_id) DO UPDATE SET
                job_id = excluded.job_id,
                stage = excluded.stage,
                current_tokens = excluded.current_tokens,
                target_tokens = excluded.target_tokens,
                updated_at = excluded.updated_at',
            [$runId, $jobId, $stage, $currentTokens, $targetTokens, $updatedAt],
        );
    }

    /**
     * Clear only if the row still belongs to this job (older finally cannot win).
     */
    public function clear(string $runId, string $jobId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM om_current_activity WHERE run_id = ? AND job_id = ?',
            [$runId, $jobId],
        );
    }

    /**
     * @return array{
     *   run_id: string,
     *   job_id: string,
     *   stage: string,
     *   current_tokens: int,
     *   target_tokens: ?int,
     *   updated_at: string
     * }|null
     */
    public function findFresh(string $runId, ?\DateTimeImmutable $now = null): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT run_id, job_id, stage, current_tokens, target_tokens, updated_at
             FROM om_current_activity WHERE run_id = ?',
            [$runId],
        );
        if (false === $row) {
            return null;
        }

        $updatedAtRaw = (string) ($row['updated_at'] ?? '');
        try {
            $updatedAt = new \DateTimeImmutable($updatedAtRaw);
        } catch (\Exception) {
            return null;
        }

        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($now->getTimestamp() - $updatedAt->getTimestamp() > self::STALE_AFTER_SECONDS) {
            return null;
        }

        $target = $row['target_tokens'] ?? null;

        return [
            'run_id' => (string) $row['run_id'],
            'job_id' => (string) $row['job_id'],
            'stage' => (string) $row['stage'],
            'current_tokens' => (int) $row['current_tokens'],
            'target_tokens' => null === $target ? null : (int) $target,
            'updated_at' => $updatedAtRaw,
        ];
    }
}
