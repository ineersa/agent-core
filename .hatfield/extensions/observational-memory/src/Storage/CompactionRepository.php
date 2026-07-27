<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Durable compaction request/result + reflection persistence with immutable request identity.
 *
 * request_fingerprint is identity; observation_set_hash is frozen later by the worker.
 */
final class CompactionRepository
{
    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_TIMED_OUT = 'timed_out';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Ensure a request row with immutable fingerprint fields.
     *
     * @return array{
     *   status: string,
     *   request_id: string,
     *   created: bool,
     *   terminal: bool,
     *   result: ?array<string, mixed>
     * }
     */
    public function ensureRequest(
        string $requestId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $requestFingerprint,
        string $now,
    ): array {
        $existing = $this->connection->fetchAssociative(
            'SELECT request_id, run_id, required_start_seq, required_end_seq, required_watermark,
                    request_fingerprint, observation_set_hash, status
             FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );

        if (false !== $existing) {
            $this->assertRequestIdentity(
                $existing,
                $runId,
                $requiredStartSeq,
                $requiredEndSeq,
                $requiredWatermark,
                $requestFingerprint,
            );

            $status = (string) ($existing['status'] ?? self::STATUS_QUEUED);
            $result = $this->getResult($requestId);
            $terminal = \in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_TIMED_OUT], true)
                || (null !== $result && \in_array((string) ($result['status'] ?? ''), [self::STATUS_SUCCEEDED, self::STATUS_FAILED], true));

            return [
                'status' => $status,
                'request_id' => $requestId,
                'created' => false,
                'terminal' => $terminal,
                'result' => $result,
            ];
        }

        try {
            $this->connection->insert('om_compaction_request', [
                'request_id' => $requestId,
                'run_id' => $runId,
                'required_start_seq' => $requiredStartSeq,
                'required_end_seq' => $requiredEndSeq,
                'required_watermark' => $requiredWatermark,
                'request_fingerprint' => $requestFingerprint,
                'observation_set_hash' => null,
                'status' => self::STATUS_QUEUED,
                'requested_at' => $now,
                'updated_at' => $now,
                'completed_at' => null,
                'failure_code' => null,
                'failure_metadata_json' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->ensureRequest(
                $requestId,
                $runId,
                $requiredStartSeq,
                $requiredEndSeq,
                $requiredWatermark,
                $requestFingerprint,
                $now,
            );
        }

        return [
            'status' => self::STATUS_QUEUED,
            'request_id' => $requestId,
            'created' => true,
            'terminal' => false,
            'result' => null,
        ];
    }

    public function markRunning(string $requestId, string $requestFingerprint, string $now): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT request_fingerprint, status FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );
        if (false === $row) {
            throw new \RuntimeException(\sprintf('Compaction request %s not found.', $requestId));
        }
        if (($row['request_fingerprint'] ?? '') !== $requestFingerprint) {
            throw new OmConflictException(\sprintf('Compaction request %s fingerprint mismatch.', $requestId));
        }

        $status = (string) ($row['status'] ?? '');
        if (\in_array($status, [self::STATUS_SUCCEEDED, self::STATUS_FAILED, self::STATUS_TIMED_OUT], true)) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE om_compaction_request SET status = ?, updated_at = ? WHERE request_id = ? AND status IN (?, ?)',
            [self::STATUS_RUNNING, $now, $requestId, self::STATUS_QUEUED, self::STATUS_RUNNING],
        );
    }

    /**
     * Short autocommit read for hook polling — no transaction held.
     *
     * @return array{
     *   request_id: string,
     *   status: string,
     *   failure_code: ?string,
     *   failure_metadata_json: ?string,
     *   result: ?array<string, mixed>
     * }|null
     */
    public function getRequestStatus(string $requestId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT request_id, status, failure_code, failure_metadata_json
             FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'request_id' => (string) $row['request_id'],
            'status' => (string) ($row['status'] ?? self::STATUS_QUEUED),
            'failure_code' => isset($row['failure_code']) ? (string) $row['failure_code'] : null,
            'failure_metadata_json' => isset($row['failure_metadata_json'])
                ? (string) $row['failure_metadata_json']
                : null,
            'result' => $this->getResult($requestId),
        ];
    }

    /**
     * @return array{
     *   result_id: string,
     *   request_id: string,
     *   run_id: string,
     *   required_watermark: int,
     *   observation_set_hash: string,
     *   status: string,
     *   replacement_text: ?string,
     *   metadata_json: ?string,
     *   failure_code: ?string,
     *   failure_metadata_json: ?string
     * }|null
     */
    public function getResult(string $requestId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT result_id, request_id, run_id, required_watermark, observation_set_hash, status,
                    replacement_text, metadata_json, failure_code, failure_metadata_json
             FROM om_compaction_result WHERE request_id = ?',
            [$requestId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'result_id' => (string) $row['result_id'],
            'request_id' => (string) $row['request_id'],
            'run_id' => (string) $row['run_id'],
            'required_watermark' => (int) $row['required_watermark'],
            'observation_set_hash' => (string) ($row['observation_set_hash'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'replacement_text' => isset($row['replacement_text'])
                ? (string) $row['replacement_text']
                : null,
            'metadata_json' => isset($row['metadata_json'])
                ? (string) $row['metadata_json']
                : null,
            'failure_code' => isset($row['failure_code'])
                ? (string) $row['failure_code']
                : null,
            'failure_metadata_json' => isset($row['failure_metadata_json'])
                ? (string) $row['failure_metadata_json']
                : null,
        ];
    }

    public function freezeObservationSetHash(string $requestId, string $observationSetHash): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT observation_set_hash, status FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );
        if (false === $row) {
            throw new \RuntimeException(\sprintf('Compaction request %s not found.', $requestId));
        }
        $existing = $row['observation_set_hash'] ?? null;
        if (null !== $existing && '' !== $existing && $existing !== $observationSetHash) {
            throw new OmConflictException(\sprintf('Compaction request %s observation_set_hash already frozen differently.', $requestId));
        }
        if ($existing === $observationSetHash) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE om_compaction_request SET observation_set_hash = ? WHERE request_id = ? AND (observation_set_hash IS NULL OR observation_set_hash = \'\')',
            [$observationSetHash, $requestId],
        );
    }

    /**
     * @param list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   compression_level: string,
     *   token_count: int
     * }> $reflections
     * @param array<string, mixed>|null $metadata
     *
     * @return array{status: 'inserted'|'noop'}
     */
    public function commitSuccess(
        string $requestId,
        string $resultId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $requestFingerprint,
        string $observationSetHash,
        string $replacementText,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        array $reflections,
        string $now,
        ?array $metadata = null,
    ): array {
        $existing = $this->getResult($requestId);
        if (null !== $existing) {
            if (($existing['observation_set_hash'] ?? '') !== $observationSetHash
                || ($existing['status'] ?? '') !== self::STATUS_SUCCEEDED
                || ($existing['replacement_text'] ?? null) !== $replacementText) {
                throw new OmConflictException(\sprintf('Compaction result conflict for request %s.', $requestId));
            }

            return ['status' => 'noop'];
        }

        $metadataJson = null;
        if (null !== $metadata) {
            try {
                $metadataJson = json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode compaction metadata.', previous: $e);
            }
        }

        // Outer transaction may already hold the write lock (generation promotion + result).
        $ownsTransaction = !$this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            // CAS: only a still-running request can become succeeded. timed_out/failed win the race.
            $cas = $this->connection->executeStatement(
                'UPDATE om_compaction_request
                 SET status = ?, updated_at = ?, completed_at = ?, failure_code = NULL, failure_metadata_json = NULL,
                     observation_set_hash = ?
                 WHERE request_id = ? AND status = ?',
                [self::STATUS_SUCCEEDED, $now, $now, $observationSetHash, $requestId, self::STATUS_RUNNING],
            );
            if (1 !== $cas) {
                $status = $this->getRequestStatus($requestId);
                if (null !== $status && self::STATUS_TIMED_OUT === $status['status']) {
                    throw new OmConflictException(\sprintf('Compaction request %s already timed out; reject late success.', $requestId));
                }
                if (null !== $status && self::STATUS_SUCCEEDED === $status['status']) {
                    $again = $this->getResult($requestId);
                    if (null !== $again
                        && ($again['observation_set_hash'] ?? '') === $observationSetHash
                        && ($again['status'] ?? '') === self::STATUS_SUCCEEDED
                        && ($again['replacement_text'] ?? null) === $replacementText) {
                        if ($ownsTransaction) {
                            $this->connection->commit();
                        }

                        return ['status' => 'noop'];
                    }
                }
                throw new OmConflictException(\sprintf('Compaction request %s is not running (status=%s); cannot commit success.', $requestId, null === $status ? 'missing' : $status['status']));
            }

            $existingRow = $this->connection->fetchAssociative(
                'SELECT request_id, run_id, required_start_seq, required_end_seq, required_watermark, request_fingerprint, status
                 FROM om_compaction_request WHERE request_id = ?',
                [$requestId],
            );
            if (false === $existingRow) {
                throw new OmConflictException(\sprintf('Compaction request %s missing after CAS.', $requestId));
            }
            $this->assertRequestIdentity(
                $existingRow,
                $runId,
                $requiredStartSeq,
                $requiredEndSeq,
                $requiredWatermark,
                $requestFingerprint,
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
                    $prior = $this->connection->fetchOne(
                        'SELECT content FROM om_reflection WHERE reflection_id = ?',
                        [$reflection['reflection_id']],
                    );
                    if ($prior !== $reflection['content']) {
                        throw new OmConflictException(\sprintf('Reflection conflict for id %s.', $reflection['reflection_id']), previous: $e);
                    }
                }
            }

            try {
                $this->connection->insert('om_compaction_result', [
                    'result_id' => $resultId,
                    'request_id' => $requestId,
                    'run_id' => $runId,
                    'required_watermark' => $requiredWatermark,
                    'observation_set_hash' => $observationSetHash,
                    'status' => self::STATUS_SUCCEEDED,
                    'replacement_text' => $replacementText,
                    'metadata_json' => $metadataJson,
                    'failure_code' => null,
                    'failure_metadata_json' => null,
                    'created_at' => $now,
                    'completed_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                if ($ownsTransaction && $this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                $again = $this->getResult($requestId);
                if (null === $again
                    || ($again['observation_set_hash'] ?? '') !== $observationSetHash
                    || ($again['status'] ?? '') !== self::STATUS_SUCCEEDED) {
                    throw new OmConflictException(\sprintf('Compaction result conflict for request %s after concurrent insert.', $requestId), previous: $e);
                }

                return ['status' => 'noop'];
            }

            if ($ownsTransaction) {
                $this->connection->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $e;
        }

        return ['status' => 'inserted'];
    }

    /**
     * Persist a durable terminal failure. Do not call for transient provider/process errors that Messenger should retry.
     *
     * @param array<string, mixed>|null $failureMetadata
     *
     * @return array{status: 'inserted'|'noop'}
     */
    public function commitFailure(
        string $requestId,
        string $resultId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $requestFingerprint,
        string $failureCode,
        string $now,
        ?array $failureMetadata = null,
    ): array {
        $request = $this->connection->fetchAssociative(
            'SELECT status FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );
        if (false !== $request && self::STATUS_TIMED_OUT === ($request['status'] ?? '')) {
            throw new OmConflictException(\sprintf('Compaction request %s already timed out; reject late failure.', $requestId));
        }

        $existing = $this->getResult($requestId);
        if (null !== $existing) {
            if (($existing['status'] ?? '') === self::STATUS_FAILED
                && ($existing['failure_code'] ?? null) === $failureCode) {
                return ['status' => 'noop'];
            }
            if (($existing['status'] ?? '') === self::STATUS_SUCCEEDED) {
                throw new OmConflictException(\sprintf('Compaction request %s already succeeded; cannot fail.', $requestId));
            }
            throw new OmConflictException(\sprintf('Compaction failure conflict for request %s.', $requestId));
        }

        $failureMetadataJson = null;
        if (null !== $failureMetadata) {
            try {
                $failureMetadataJson = json_encode($failureMetadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Failed to encode compaction failure metadata.', previous: $e);
            }
        }

        $this->connection->beginTransaction();
        try {
            $this->assertAndTouchRequest(
                $requestId,
                $runId,
                $requiredStartSeq,
                $requiredEndSeq,
                $requiredWatermark,
                $requestFingerprint,
                self::STATUS_FAILED,
                $now,
                $failureCode,
                $failureMetadataJson,
            );

            try {
                $this->connection->insert('om_compaction_result', [
                    'result_id' => $resultId,
                    'request_id' => $requestId,
                    'run_id' => $runId,
                    'required_watermark' => $requiredWatermark,
                    'observation_set_hash' => '',
                    'status' => self::STATUS_FAILED,
                    'replacement_text' => null,
                    'metadata_json' => null,
                    'failure_code' => $failureCode,
                    'failure_metadata_json' => $failureMetadataJson,
                    'created_at' => $now,
                    'completed_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $this->connection->rollBack();
                $again = $this->getResult($requestId);
                if (null !== $again && ($again['status'] ?? '') === self::STATUS_FAILED && ($again['failure_code'] ?? null) === $failureCode) {
                    return ['status' => 'noop'];
                }
                throw new OmConflictException(\sprintf('Compaction failure conflict for request %s after concurrent insert.', $requestId), previous: $e);
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

    public function markTimedOut(string $requestId, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE om_compaction_request
             SET status = ?, updated_at = ?, completed_at = ?, failure_code = ?
             WHERE request_id = ? AND status IN (?, ?)',
            [self::STATUS_TIMED_OUT, $now, $now, 'timed_out', $requestId, self::STATUS_QUEUED, self::STATUS_RUNNING],
        );
    }

    /**
     * @param array<string, mixed> $existing
     */
    private function assertRequestIdentity(
        array $existing,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $requestFingerprint,
    ): void {
        if ((string) ($existing['run_id'] ?? '') !== $runId
            || (int) ($existing['required_start_seq'] ?? 0) !== $requiredStartSeq
            || (int) ($existing['required_end_seq'] ?? 0) !== $requiredEndSeq
            || (int) ($existing['required_watermark'] ?? 0) !== $requiredWatermark
            || (string) ($existing['request_fingerprint'] ?? '') !== $requestFingerprint) {
            throw new OmConflictException(\sprintf('Compaction request identity conflict for %s.', (string) ($existing['request_id'] ?? 'unknown')));
        }
    }

    private function assertAndTouchRequest(
        string $requestId,
        string $runId,
        int $requiredStartSeq,
        int $requiredEndSeq,
        int $requiredWatermark,
        string $requestFingerprint,
        string $status,
        string $now,
        ?string $failureCode,
        ?string $failureMetadataJson,
    ): void {
        $existing = $this->connection->fetchAssociative(
            'SELECT request_id, run_id, required_start_seq, required_end_seq, required_watermark, request_fingerprint, status
             FROM om_compaction_request WHERE request_id = ?',
            [$requestId],
        );
        if (false === $existing) {
            $this->connection->insert('om_compaction_request', [
                'request_id' => $requestId,
                'run_id' => $runId,
                'required_start_seq' => $requiredStartSeq,
                'required_end_seq' => $requiredEndSeq,
                'required_watermark' => $requiredWatermark,
                'request_fingerprint' => $requestFingerprint,
                'observation_set_hash' => null,
                'status' => $status,
                'requested_at' => $now,
                'updated_at' => $now,
                'completed_at' => $now,
                'failure_code' => $failureCode,
                'failure_metadata_json' => $failureMetadataJson,
            ]);

            return;
        }

        $this->assertRequestIdentity(
            $existing,
            $runId,
            $requiredStartSeq,
            $requiredEndSeq,
            $requiredWatermark,
            $requestFingerprint,
        );

        $this->connection->executeStatement(
            'UPDATE om_compaction_request
             SET status = ?, updated_at = ?, completed_at = ?, failure_code = ?, failure_metadata_json = ?
             WHERE request_id = ?',
            [$status, $now, $now, $failureCode, $failureMetadataJson, $requestId],
        );
    }
}
