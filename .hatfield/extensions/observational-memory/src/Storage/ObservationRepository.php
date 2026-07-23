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
