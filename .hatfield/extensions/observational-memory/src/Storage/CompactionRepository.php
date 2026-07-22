<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Durable compaction request/result + reflection persistence.
 */
final class CompactionRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   compression_level: string,
     *   token_count: int
     * }> $reflections
     *
     * @return array{status: 'inserted'|'noop'}
     */
    public function commitResult(
        string $requestId,
        string $resultId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $observationSetHash,
        string $status,
        ?string $replacementText,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        array $reflections,
        string $now,
        ?string $failureCode = null,
        ?string $failureMetadataJson = null,
    ): array {
        $existing = $this->connection->fetchAssociative(
            'SELECT result_id, observation_set_hash, status FROM om_compaction_result WHERE request_id = ?',
            [$requestId],
        );

        if (false !== $existing) {
            if (($existing['observation_set_hash'] ?? '') !== $observationSetHash) {
                throw new OmConflictException(\sprintf('Compaction result conflict for request %s: observation_set_hash mismatch.', $requestId));
            }

            return ['status' => 'noop'];
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement(
                'INSERT INTO om_compaction_request (
                    request_id, run_id, required_start_seq, required_end_seq, required_watermark,
                    observation_set_hash, status, requested_at, updated_at, completed_at,
                    failure_code, failure_metadata_json
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(request_id) DO UPDATE SET
                    status = excluded.status,
                    updated_at = excluded.updated_at,
                    completed_at = excluded.completed_at,
                    failure_code = excluded.failure_code,
                    failure_metadata_json = excluded.failure_metadata_json',
                [
                    $requestId,
                    $runId,
                    $requiredStartSeq,
                    $requiredEndSeq,
                    $requiredWatermark,
                    $observationSetHash,
                    $status,
                    $now,
                    $now,
                    $now,
                    $failureCode,
                    $failureMetadataJson,
                ],
            );

            foreach ($reflections as $reflection) {
                try {
                    $this->connection->insert('om_reflection', [
                        'reflection_id' => $reflection['reflection_id'],
                        'run_id' => $runId,
                        'compaction_request_id' => $requestId,
                        'observation_set_hash' => $observationSetHash,
                        'content' => $reflection['content'],
                        'supporting_observation_ids_json' => $reflection['supporting_observation_ids_json'],
                        'compression_level' => $reflection['compression_level'],
                        'token_count' => $reflection['token_count'],
                        'reflector_model' => $reflectorModel,
                        'reflector_schema_version' => $reflectorSchemaVersion,
                        'created_at' => $now,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // Compatible redelivery of the same reflection identity.
                }
            }

            try {
                $this->connection->insert('om_compaction_result', [
                    'result_id' => $resultId,
                    'request_id' => $requestId,
                    'run_id' => $runId,
                    'required_watermark' => $requiredWatermark,
                    'observation_set_hash' => $observationSetHash,
                    'status' => $status,
                    'replacement_text' => $replacementText,
                    'metadata_json' => null,
                    'failure_code' => $failureCode,
                    'failure_metadata_json' => $failureMetadataJson,
                    'created_at' => $now,
                    'completed_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $this->connection->rollBack();
                $again = $this->connection->fetchAssociative(
                    'SELECT observation_set_hash FROM om_compaction_result WHERE request_id = ?',
                    [$requestId],
                );
                if (false === $again || ($again['observation_set_hash'] ?? '') !== $observationSetHash) {
                    throw new OmConflictException(\sprintf('Compaction result conflict for request %s after concurrent insert.', $requestId), previous: $e);
                }

                return ['status' => 'noop'];
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return ['status' => 'inserted'];
    }
}
