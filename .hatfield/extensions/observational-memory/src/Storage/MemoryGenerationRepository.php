<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;

/**
 * Active-generation ledger reads/writes for threshold + compaction Reflector paths.
 *
 * Full Reflector commit is slice 3; this slice provides read/projection + existence checks.
 */
final class MemoryGenerationRepository
{
    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string TRIGGER_THRESHOLD = 'threshold';

    public const string TRIGGER_COMPACTION = 'compaction';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function activeGenerationId(string $runId): ?string
    {
        $id = $this->connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            [$runId],
        );

        return \is_string($id) && '' !== $id ? $id : null;
    }

    /**
     * @return list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int,
     *   position: int
     * }>
     */
    public function listActiveReflections(string $runId): array
    {
        $generationId = $this->activeGenerationId($runId);
        if (null === $generationId) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT gr.reflection_id, gr.position, r.content, r.supporting_observation_ids_json, r.token_count
             FROM om_generation_reflection gr
             INNER JOIN om_reflection r ON r.reflection_id = gr.reflection_id
             WHERE gr.generation_id = ?
             ORDER BY gr.position ASC',
            [$generationId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'reflection_id' => (string) ($row['reflection_id'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'supporting_observation_ids_json' => (string) ($row['supporting_observation_ids_json'] ?? '[]'),
                'token_count' => (int) ($row['token_count'] ?? 0),
                'position' => (int) ($row['position'] ?? 0),
            ];
        }

        return $out;
    }

    public function hasRunningOrSucceededForSet(string $runId, string $observationSetHash): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(1) FROM om_memory_generation
             WHERE run_id = ? AND observation_set_hash = ? AND status IN (?, ?)',
            [$runId, $observationSetHash, self::STATUS_RUNNING, self::STATUS_SUCCEEDED],
        );

        return (int) $count > 0;
    }

    public function hasGenerationId(string $generationId): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );

        return false !== $found && null !== $found;
    }
}
