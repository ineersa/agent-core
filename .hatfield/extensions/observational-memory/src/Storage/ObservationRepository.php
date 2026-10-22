<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Ineersa\HatfieldExt\ObservationalMemory\Observer\OmTokenEstimator;
use Ineersa\HatfieldExt\ObservationalMemory\Support\OmIdentity;

/**
 * Durable observation + coverage persistence with idempotent redelivery semantics.
 */
final class ObservationRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function hasCompatibleCoverage(string $coverageKey, string $sourceDigest, string $partDigest): bool
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT source_digest, part_digest FROM om_coverage WHERE coverage_key = ?',
            [$coverageKey],
        );

        if (false === $existing) {
            return false;
        }

        if (($existing['source_digest'] ?? '') !== $sourceDigest
            || ($existing['part_digest'] ?? '') !== $partDigest) {
            throw new OmConflictException(\sprintf('Coverage conflict for key %s: digest mismatch.', $coverageKey));
        }

        return true;
    }

    /**
     * Contiguous covered end seq from source seq 1 under active renderer/schema versions.
     *
     * Complete chunks only; never MAX(source_end_seq).
     */
    public function contiguousCoveredEndSeq(string $runId, string $rendererVersion, string $observerSchemaVersion): ?int
    {
        $intervals = $this->connection->iterateAssociative(
            'SELECT chunk_key, MIN(CAST(source_start_seq AS INTEGER)) AS source_start_seq, MIN(CAST(source_end_seq AS INTEGER)) AS source_end_seq
             FROM om_coverage
             WHERE run_id = ? AND renderer_version = ? AND observer_schema_version = ?
               AND chunk_key IS NOT NULL AND chunk_key <> \'\'
             GROUP BY chunk_key
             HAVING MIN(CAST(part_count AS INTEGER)) >= 1
                AND COUNT(part_count) = COUNT(*) AND COUNT(part_index) = COUNT(*)
                AND COUNT(source_start_seq) = COUNT(*) AND COUNT(source_end_seq) = COUNT(*)
                AND MIN(CAST(part_count AS INTEGER)) = MAX(CAST(part_count AS INTEGER))
                AND MIN(CAST(source_start_seq AS INTEGER)) >= 1
                AND MIN(CAST(source_start_seq AS INTEGER)) = MAX(CAST(source_start_seq AS INTEGER))
                AND MIN(CAST(source_end_seq AS INTEGER)) >= MIN(CAST(source_start_seq AS INTEGER))
                AND MIN(CAST(source_end_seq AS INTEGER)) = MAX(CAST(source_end_seq AS INTEGER))
                AND MIN(source_digest) <> \'\' AND MIN(source_digest) = MAX(source_digest)
                AND COUNT(source_digest) = COUNT(*)
                AND MIN(CAST(part_index AS INTEGER)) = 1
                AND MAX(CAST(part_index AS INTEGER)) = MIN(CAST(part_count AS INTEGER))
                AND COUNT(DISTINCT CAST(part_index AS INTEGER)) = MIN(CAST(part_count AS INTEGER))
             ORDER BY source_start_seq ASC, source_end_seq DESC, chunk_key ASC',
            [$runId, $rendererVersion, $observerSchemaVersion],
        );

        $expected = 1;
        foreach ($intervals as $interval) {
            $start = (int) $interval['source_start_seq'];
            $end = (int) $interval['source_end_seq'];
            if ($end < $expected) {
                continue;
            }
            if ($start > $expected) {
                break;
            }
            $expected = max($expected, $end + 1);
        }

        return 1 === $expected ? null : $expected - 1;
    }

    /**
     * Active candidate observations for Observer/Reflector input and threshold tokens.
     *
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }>
     */
    public function listActiveCandidateObservations(string $runId): array
    {
        return $this->activeCandidateRows($runId);
    }

    /** @return array{count: int, token_count: int} */
    public function activeCandidateStats(string $runId): array
    {
        return $this->connection->transactional(function () use ($runId): array {
            // Persisted token_count is not authoritative. Ordinary text stays in
            // SQL; only NUL-containing bodies need the PHP Unicode estimator.
            $estimate = OmTokenEstimator::sqliteEstimate('o.content');
            [$predicate, $parameters] = $this->activeCandidatePredicate($runId);
            $nul = 'instr(o.content, char(0)) > 0';
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS count,
                    COALESCE(SUM(CASE WHEN '.$nul.' THEN 0 ELSE '.$estimate.' END), 0) AS token_count,
                    COALESCE(SUM(CASE WHEN '.$nul.' THEN 1 ELSE 0 END), 0) AS nul_count
                 FROM om_observation o WHERE '.$predicate,
                $parameters,
            );
            $tokens = (int) $row['token_count'];
            if (0 !== (int) $row['nul_count']) {
                foreach ($this->connection->iterateColumn('SELECT o.content FROM om_observation o WHERE '.$predicate.' AND '.$nul, $parameters) as $content) {
                    $tokens += OmTokenEstimator::estimate((string) $content);
                }
            }

            return ['count' => (int) $row['count'], 'token_count' => $tokens];
        });
    }

    public function countForRun(string $runId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM om_observation WHERE run_id = ?', [$runId]);
    }

    /** @param list<string> $ids
     * @param (\Closure(): void)|null $checkpoint
     *
     * @return array<string, string> observation ID => source references JSON
     */
    public function supportReferences(string $runId, array $ids, ?\Closure $checkpoint = null): array
    {
        $references = [];
        foreach (array_chunk(array_values(array_unique($ids)), 400) as $page) {
            $checkpoint?->__invoke();
            foreach ($this->connection->fetchAllAssociative(
                'SELECT observation_id, source_refs_json FROM om_observation WHERE run_id = ? AND observation_id IN (?)',
                [$runId, $page],
                [ParameterType::STRING, ArrayParameterType::STRING],
            ) as $row) {
                $references[(string) $row['observation_id']] = (string) $row['source_refs_json'];
            }
            $checkpoint?->__invoke();
        }

        return $references;
    }

    /**
     * Exact observation lookup for recall / status-view (SQL-scoped to current run).
     *
     * @return array{
     *   observation_id: string,
     *   run_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }|null
     */
    public function findObservation(string $runId, string $observationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT observation_id, run_id, content, content_hash, relevance, timestamp, token_count,
                    source_refs_json, source_start_seq, source_end_seq, created_at
             FROM om_observation WHERE run_id = ? AND observation_id = ?',
            [$runId, $observationId],
        );
        if (false === $row) {
            return null;
        }

        $mapped = $this->mapObservationRow($row);
        $mapped['run_id'] = (string) ($row['run_id'] ?? '');

        return $mapped;
    }

    /**
     * Exact or unique-prefix observation lookup (SQL-scoped to current run).
     *
     * @return list<array{
     *   observation_id: string,
     *   run_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }>
     */
    public function findObservationsByIdPrefix(string $runId, string $idPrefix): array
    {
        $idPrefix = strtolower(trim($idPrefix));
        if ('' === $idPrefix) {
            return [];
        }

        // Exact full id first (cheap primary-key path).
        if (64 === \strlen($idPrefix)) {
            $exact = $this->findObservation($runId, $idPrefix);

            return null === $exact ? [] : [$exact];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT observation_id, run_id, content, content_hash, relevance, timestamp, token_count,
                    source_refs_json, source_start_seq, source_end_seq, created_at
             FROM om_observation
             WHERE run_id = ? AND observation_id LIKE ?
             ORDER BY observation_id ASC
             LIMIT 3',
            [$runId, $idPrefix.'%'],
        );

        $out = [];
        foreach ($rows as $row) {
            $mapped = $this->mapObservationRow($row);
            $mapped['run_id'] = (string) ($row['run_id'] ?? '');
            $out[] = $mapped;
        }

        return $out;
    }

    /**
     * Exact substring search over retained observations (all sessions in this database).
     *
     * Uses LIKE with a backslash ESCAPE clause. Leading-wildcard patterns scan the table;
     * callers must keep result limits small. Optional filters compare memory `timestamp`
     * (YYYY-MM-DD HH:MM).
     *
     * @return list<array{
     *   observation_id: string,
     *   run_id: string,
     *   content: string,
     *   relevance: string,
     *   timestamp: string
     * }>
     */
    public function searchContent(
        string $query,
        ?string $afterTimestamp = null,
        ?string $beforeTimestamp = null,
        int $limit = 20,
    ): array {
        $query = trim($query);
        if ('' === $query || $limit < 1) {
            return [];
        }

        $sql = 'SELECT observation_id, run_id, content, relevance, timestamp '
            .'FROM om_observation '
            ."WHERE content LIKE ? ESCAPE '\\'";
        $params = [$this->likeContainsPattern($query)];

        if (null !== $afterTimestamp && '' !== $afterTimestamp) {
            $sql .= ' AND timestamp >= ?';
            $params[] = $afterTimestamp;
        }
        if (null !== $beforeTimestamp && '' !== $beforeTimestamp) {
            $sql .= ' AND timestamp <= ?';
            $params[] = $beforeTimestamp;
        }

        $sql .= ' ORDER BY timestamp DESC, observation_id ASC LIMIT '.$limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'observation_id' => (string) ($row['observation_id'] ?? ''),
                'run_id' => (string) ($row['run_id'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'relevance' => (string) ($row['relevance'] ?? OmIdentity::RELEVANCE_MEDIUM),
                'timestamp' => (string) ($row['timestamp'] ?? '1970-01-01 00:00'),
            ];
        }

        return $out;
    }

    /**
     * Active candidate set for threshold tokens / observation_set_hash / Reflector input.
     *
     * Before first generation: all observations for the run.
     * After a succeeded active generation: retained observations for that generation
     * UNION observations with source_end_seq greater than the generation required_end_seq
     * watermark (committed-source checkpoint). Same-second commits are ordered by seq,
     * not created_at, so later source seq is included without resurrecting dropped rows.
     *
     * @return array{
     *   observation_ids: list<string>,
     *   token_count: int,
     *   observation_set_hash: string,
     *   max_source_end_seq: int,
     *   observations: list<array{
     *     observation_id: string,
     *     content: string,
     *     content_hash: string,
     *     relevance: string,
     *     timestamp: string,
     *     token_count: int,
     *     source_refs_json: string,
     *     source_start_seq: int,
     *     source_end_seq: int,
     *     created_at: string
     *   }>
     * }
     */
    public function activeCandidateSet(string $runId): array
    {
        return $this->buildCandidateSetPayload($runId, $this->activeCandidateRows($runId));
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string
     * }> $observations
     *
     * @return array{status: 'inserted'|'noop', observation_count: int}
     */
    public function commitChunkPartCoverage(
        string $coverageKey,
        string $runId,
        string $boundaryKey,
        int $sourceStartSeq,
        int $sourceEndSeq,
        string $chunkKey,
        int $partIndex,
        int $partCount,
        string $sourceDigest,
        string $partDigest,
        string $rendererVersion,
        string $observerSchemaVersion,
        string $observerModel,
        array $observations,
        string $coveredAt,
    ): array {
        $existing = $this->connection->fetchAssociative(
            'SELECT coverage_key, source_digest, part_digest, observation_count FROM om_coverage WHERE coverage_key = ?',
            [$coverageKey],
        );

        if (false !== $existing) {
            if (($existing['source_digest'] ?? '') !== $sourceDigest
                || ($existing['part_digest'] ?? '') !== $partDigest) {
                throw new OmConflictException(\sprintf('Coverage conflict for key %s: digest mismatch.', $coverageKey));
            }

            return [
                'status' => 'noop',
                'observation_count' => (int) ($existing['observation_count'] ?? 0),
            ];
        }

        $this->connection->beginTransaction();
        try {
            foreach ($observations as $observation) {
                try {
                    $this->connection->insert('om_observation', [
                        'observation_id' => $observation['observation_id'],
                        'run_id' => $runId,
                        'boundary_key' => $boundaryKey,
                        'source_start_seq' => $sourceStartSeq,
                        'source_end_seq' => $sourceEndSeq,
                        'source_refs_json' => $observation['source_refs_json'],
                        'content' => $observation['content'],
                        'content_hash' => $observation['content_hash'],
                        'relevance' => $observation['relevance'],
                        'timestamp' => $observation['timestamp'],
                        'token_count' => $observation['token_count'],
                        'observer_model' => $observerModel,
                        'observer_schema_version' => $observerSchemaVersion,
                        'created_at' => $coveredAt,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    $prior = $this->connection->fetchOne(
                        'SELECT content_hash FROM om_observation WHERE observation_id = ?',
                        [$observation['observation_id']],
                    );
                    if ($prior !== $observation['content_hash']) {
                        throw new OmConflictException(\sprintf('Observation conflict for id %s: content_hash mismatch.', $observation['observation_id']), previous: $e);
                    }
                }
            }

            try {
                $this->connection->insert('om_coverage', [
                    'coverage_key' => $coverageKey,
                    'run_id' => $runId,
                    'boundary_key' => $boundaryKey,
                    'source_start_seq' => $sourceStartSeq,
                    'source_end_seq' => $sourceEndSeq,
                    'chunk_key' => $chunkKey,
                    'part_index' => $partIndex,
                    'part_count' => $partCount,
                    'source_digest' => $sourceDigest,
                    'part_digest' => $partDigest,
                    'renderer_version' => $rendererVersion,
                    'observer_schema_version' => $observerSchemaVersion,
                    'observation_count' => \count($observations),
                    'covered_at' => $coveredAt,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $this->connection->rollBack();
                $again = $this->connection->fetchAssociative(
                    'SELECT source_digest, part_digest, observation_count FROM om_coverage WHERE coverage_key = ?',
                    [$coverageKey],
                );
                if (false === $again
                    || ($again['source_digest'] ?? '') !== $sourceDigest
                    || ($again['part_digest'] ?? '') !== $partDigest) {
                    throw new OmConflictException(\sprintf('Coverage conflict for key %s after concurrent insert.', $coverageKey), previous: $e);
                }

                return [
                    'status' => 'noop',
                    'observation_count' => (int) ($again['observation_count'] ?? 0),
                ];
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return [
            'status' => 'inserted',
            'observation_count' => \count($observations),
        ];
    }

    /** @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }> */
    private function activeCandidateRows(string $runId): array
    {
        [$predicate, $parameters] = $this->activeCandidatePredicate($runId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.observation_id, o.content, o.content_hash, o.relevance, o.timestamp, o.token_count,
                    o.source_refs_json, o.source_start_seq, o.source_end_seq, o.created_at
             FROM om_observation o WHERE '.$predicate.' ORDER BY o.timestamp ASC, o.observation_id ASC',
            $parameters,
        );

        return array_map($this->mapObservationRow(...), $rows);
    }

    /** @return array{string, list<string|int>} */
    private function activeCandidatePredicate(string $runId): array
    {
        $activeGenerationId = $this->connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            [$runId],
        );

        if (!\is_string($activeGenerationId) || '' === $activeGenerationId) {
            return ['o.run_id = ?', [$runId]];
        }

        $watermark = $this->connection->fetchOne(
            'SELECT required_end_seq FROM om_memory_generation
             WHERE generation_id = ? AND status = ?',
            [$activeGenerationId, 'succeeded'],
        );
        if (!\is_int($watermark) && !(\is_string($watermark) && is_numeric($watermark))) {
            return ['o.run_id = ?', [$runId]];
        }

        return [
            'o.run_id = ? AND (
                    EXISTS (
                        SELECT 1 FROM om_generation_retained_observation r
                        WHERE r.generation_id = ? AND r.observation_id = o.observation_id
                    )
                    OR o.source_end_seq > ?
               )',
            [$runId, $activeGenerationId, (int) $watermark],
        ];
    }

    private function likeContainsPattern(string $query): string
    {
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $query,
        );

        return '%'.$escaped.'%';
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }> $observations
     *
     * @return array{
     *   observation_ids: list<string>,
     *   token_count: int,
     *   observation_set_hash: string,
     *   max_source_end_seq: int,
     *   observations: list<array{
     *     observation_id: string,
     *     content: string,
     *     content_hash: string,
     *     relevance: string,
     *     timestamp: string,
     *     token_count: int,
     *     source_refs_json: string,
     *     source_start_seq: int,
     *     source_end_seq: int,
     *     created_at: string
     *   }>
     * }
     */
    private function buildCandidateSetPayload(string $runId, array $observations): array
    {
        $ids = [];
        $tokens = 0;
        $maxSourceEndSeq = 0;
        foreach ($observations as $observation) {
            $ids[] = $observation['observation_id'];
            $tokens += OmTokenEstimator::estimate($observation['content']);
            $maxSourceEndSeq = max($maxSourceEndSeq, $observation['source_end_seq']);
        }
        sort($ids, \SORT_STRING);

        return [
            'observation_ids' => $ids,
            'token_count' => $tokens,
            // Hash remains IDs-only (task formula observation-set-v1).
            'observation_set_hash' => OmIdentity::observationSetHash($runId, $ids),
            'max_source_end_seq' => $maxSourceEndSeq,
            'observations' => $observations,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: string,
     *   timestamp: string,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int,
     *   created_at: string
     * }
     */
    private function mapObservationRow(array $row): array
    {
        return [
            'observation_id' => (string) ($row['observation_id'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'content_hash' => (string) ($row['content_hash'] ?? ''),
            'relevance' => (string) ($row['relevance'] ?? OmIdentity::RELEVANCE_MEDIUM),
            'timestamp' => (string) ($row['timestamp'] ?? '1970-01-01 00:00'),
            'token_count' => (int) ($row['token_count'] ?? 0),
            'source_refs_json' => (string) ($row['source_refs_json'] ?? '[]'),
            'source_start_seq' => (int) ($row['source_start_seq'] ?? 0),
            'source_end_seq' => (int) ($row['source_end_seq'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
