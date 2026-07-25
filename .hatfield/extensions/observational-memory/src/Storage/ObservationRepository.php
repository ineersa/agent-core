<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Durable observation + coverage persistence with idempotent redelivery semantics.
 */
final class ObservationRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function hasCompatibleCoverage(string $coverageKey, string $sourceDigest): bool
    {
        $existing = $this->connection->fetchAssociative(
            'SELECT source_digest FROM om_coverage WHERE coverage_key = ?',
            [$coverageKey],
        );

        if (false === $existing) {
            return false;
        }

        if (($existing['source_digest'] ?? '') !== $sourceDigest) {
            throw new OmConflictException(\sprintf('Coverage conflict for key %s: source_digest mismatch.', $coverageKey));
        }

        return true;
    }

    /**
     * Contiguous covered end seq from source seq 1 under active renderer/schema versions.
     *
     * Does not trust MAX(source_end_seq): a later island cannot hide an earlier gap.
     */
    public function contiguousCoveredEndSeq(string $runId, string $rendererVersion, string $observerSchemaVersion): ?int
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT source_start_seq, source_end_seq FROM om_coverage
             WHERE run_id = ? AND renderer_version = ? AND observer_schema_version = ?
             ORDER BY source_start_seq ASC, source_end_seq ASC',
            [$runId, $rendererVersion, $observerSchemaVersion],
        );

        if ([] === $rows) {
            return null;
        }

        $cursor = 0;
        foreach ($rows as $row) {
            $start = (int) ($row['source_start_seq'] ?? 0);
            $end = (int) ($row['source_end_seq'] ?? 0);
            if ($end < $start || $start < 1) {
                continue;
            }

            if (0 === $cursor) {
                if (1 !== $start) {
                    return null;
                }
                $cursor = $end;
                continue;
            }

            if ($start > $cursor + 1) {
                break;
            }

            if ($end > $cursor) {
                $cursor = $end;
            }
        }

        return $cursor > 0 ? $cursor : null;
    }

    /**
     * @deprecated use contiguousCoveredEndSeq(); kept for temporary call-site clarity
     */
    public function latestCoveredEndSeq(string $runId, string $rendererVersion, string $observerSchemaVersion): ?int
    {
        return $this->contiguousCoveredEndSeq($runId, $rendererVersion, $observerSchemaVersion);
    }

    /**
     * @return list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: int,
     *   token_count: int,
     *   source_refs_json: string,
     *   source_start_seq: int,
     *   source_end_seq: int
     * }>
     */
    public function listObservationsForRun(string $runId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT observation_id, content, content_hash, relevance, token_count, source_refs_json,
                    source_start_seq, source_end_seq
             FROM om_observation
             WHERE run_id = ?
             ORDER BY source_start_seq ASC, source_end_seq ASC, observation_id ASC',
            [$runId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'observation_id' => (string) ($row['observation_id'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
                'relevance' => (int) ($row['relevance'] ?? 0),
                'token_count' => (int) ($row['token_count'] ?? 0),
                'source_refs_json' => (string) ($row['source_refs_json'] ?? '[]'),
                'source_start_seq' => (int) ($row['source_start_seq'] ?? 0),
                'source_end_seq' => (int) ($row['source_end_seq'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{
     *   observation_id: string,
     *   content: string,
     *   content_hash: string,
     *   relevance: int,
     *   token_count: int,
     *   source_refs_json: string
     * }> $observations
     *
     * @return array{status: 'inserted'|'noop', observation_count: int}
     */
    public function commitBoundaryCoverage(
        string $coverageKey,
        string $runId,
        string $boundaryKey,
        int $sourceStartSeq,
        int $sourceEndSeq,
        string $sourceDigest,
        string $rendererVersion,
        string $observerSchemaVersion,
        string $observerModel,
        array $observations,
        string $coveredAt,
    ): array {
        $existing = $this->connection->fetchAssociative(
            'SELECT coverage_key, source_digest, observation_count FROM om_coverage WHERE coverage_key = ?',
            [$coverageKey],
        );

        if (false !== $existing) {
            if (($existing['source_digest'] ?? '') !== $sourceDigest) {
                throw new OmConflictException(\sprintf('Coverage conflict for key %s: source_digest mismatch.', $coverageKey));
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
                    // Compatible redelivery of the same observation row.
                }
            }

            try {
                $this->connection->insert('om_coverage', [
                    'coverage_key' => $coverageKey,
                    'run_id' => $runId,
                    'boundary_key' => $boundaryKey,
                    'source_start_seq' => $sourceStartSeq,
                    'source_end_seq' => $sourceEndSeq,
                    'source_digest' => $sourceDigest,
                    'renderer_version' => $rendererVersion,
                    'observer_schema_version' => $observerSchemaVersion,
                    'observation_count' => \count($observations),
                    'covered_at' => $coveredAt,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Concurrent insert of the same coverage key — treat as noop if digest matches.
                $this->connection->rollBack();
                $again = $this->connection->fetchAssociative(
                    'SELECT source_digest, observation_count FROM om_coverage WHERE coverage_key = ?',
                    [$coverageKey],
                );
                if (false === $again || ($again['source_digest'] ?? '') !== $sourceDigest) {
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
}
