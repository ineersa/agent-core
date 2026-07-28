<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        $rows = $this->connection->fetchAllAssociative(
            'SELECT coverage_key, chunk_key, part_index, part_count, source_start_seq, source_end_seq, source_digest
             FROM om_coverage
             WHERE run_id = ? AND renderer_version = ? AND observer_schema_version = ?',
            [$runId, $rendererVersion, $observerSchemaVersion],
        );

        if ([] === $rows) {
            return null;
        }

        /** @var array<string, list<array<string, mixed>>> $byChunk */
        $byChunk = [];
        foreach ($rows as $row) {
            $chunkKey = (string) ($row['chunk_key'] ?? '');
            if ('' === $chunkKey) {
                continue;
            }
            $byChunk[$chunkKey][] = $row;
        }

        $intervals = [];
        foreach ($byChunk as $chunkKey => $parts) {
            $partCount = null;
            $start = null;
            $end = null;
            $digest = null;
            $indexes = [];
            foreach ($parts as $part) {
                $pc = (int) ($part['part_count'] ?? 0);
                $s = (int) ($part['source_start_seq'] ?? 0);
                $e = (int) ($part['source_end_seq'] ?? 0);
                $d = (string) ($part['source_digest'] ?? '');
                $pi = (int) ($part['part_index'] ?? 0);
                if ($pc < 1 || $s < 1 || $e < $s || '' === $d || $pi < 1) {
                    continue 2;
                }
                if (null === $partCount) {
                    $partCount = $pc;
                    $start = $s;
                    $end = $e;
                    $digest = $d;
                } elseif ($partCount !== $pc || $start !== $s || $end !== $e || $digest !== $d) {
                    continue 2;
                }
                $indexes[$pi] = true;
            }

            if (null === $partCount || null === $start || null === $end) {
                continue;
            }

            $distinct = \count($indexes);
            if ($distinct !== $partCount) {
                continue;
            }
            $min = min(array_keys($indexes));
            $max = max(array_keys($indexes));
            if (1 !== $min || $max !== $partCount) {
                continue;
            }

            $intervals[] = [
                'chunk_key' => $chunkKey,
                'source_start_seq' => $start,
                'source_end_seq' => $end,
            ];
        }

        if ([] === $intervals) {
            return null;
        }

        usort($intervals, static function (array $a, array $b): int {
            $byStart = $a['source_start_seq'] <=> $b['source_start_seq'];
            if (0 !== $byStart) {
                return $byStart;
            }
            $byEnd = $b['source_end_seq'] <=> $a['source_end_seq'];
            if (0 !== $byEnd) {
                return $byEnd;
            }

            return strcmp($a['chunk_key'], $b['chunk_key']);
        });

        $expected = 1;
        foreach ($intervals as $interval) {
            $start = $interval['source_start_seq'];
            $end = $interval['source_end_seq'];
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
    public function listObservationsForRun(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT observation_id, content, content_hash, relevance, timestamp, token_count, source_refs_json,
                    source_start_seq, source_end_seq, created_at
             FROM om_observation
             WHERE run_id = ?
             ORDER BY timestamp ASC, observation_id ASC',
            [$runId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapObservationRow($row);
        }

        return $out;
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
        return $this->activeCandidateSet($runId)['observations'];
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
        $activeGenerationId = $this->connection->fetchOne(
            'SELECT generation_id FROM om_active_generation WHERE run_id = ?',
            [$runId],
        );

        if (!\is_string($activeGenerationId) || '' === $activeGenerationId) {
            $observations = $this->listObservationsForRun($runId);

            return $this->buildCandidateSetPayload($runId, $observations);
        }

        $watermark = $this->connection->fetchOne(
            'SELECT required_end_seq FROM om_memory_generation
             WHERE generation_id = ? AND status = ?',
            [$activeGenerationId, 'succeeded'],
        );
        if (!\is_int($watermark) && !(\is_string($watermark) && is_numeric($watermark))) {
            $observations = $this->listObservationsForRun($runId);

            return $this->buildCandidateSetPayload($runId, $observations);
        }
        $requiredEndSeq = (int) $watermark;

        // One query: retained rows for the active generation + observations committed after
        // that generation's source watermark. No per-id fetch loop.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.observation_id, o.content, o.content_hash, o.relevance, o.timestamp, o.token_count,
                    o.source_refs_json, o.source_start_seq, o.source_end_seq, o.created_at
             FROM om_observation o
             WHERE o.run_id = ?
               AND (
                    EXISTS (
                        SELECT 1 FROM om_generation_retained_observation r
                        WHERE r.generation_id = ? AND r.observation_id = o.observation_id
                    )
                    OR o.source_end_seq > ?
               )
             ORDER BY o.timestamp ASC, o.observation_id ASC',
            [$runId, $activeGenerationId, $requiredEndSeq],
        );

        $observations = [];
        foreach ($rows as $row) {
            $observations[] = $this->mapObservationRow($row);
        }

        return $this->buildCandidateSetPayload($runId, $observations);
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
