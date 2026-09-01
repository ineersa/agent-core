<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Active-generation ledger with process-safe claim + transactional promotion.
 */
final class MemoryGenerationRepository
{
    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string TRIGGER_THRESHOLD = 'threshold';

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
     * Exact reflection lookup for recall (SQL-scoped to current run).
     *
     * @return array{
     *   reflection_id: string,
     *   run_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }|null
     */
    public function findReflection(string $runId, string $reflectionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT reflection_id, run_id, content, supporting_observation_ids_json, token_count
             FROM om_reflection WHERE run_id = ? AND reflection_id = ?',
            [$runId, $reflectionId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'reflection_id' => (string) ($row['reflection_id'] ?? ''),
            'run_id' => (string) ($row['run_id'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'supporting_observation_ids_json' => (string) ($row['supporting_observation_ids_json'] ?? '[]'),
            'token_count' => (int) ($row['token_count'] ?? 0),
        ];
    }

    /**
     * Exact or unique-prefix reflection lookup (SQL-scoped to current run).
     *
     * @return list<array{
     *   reflection_id: string,
     *   run_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }>
     */
    public function findReflectionsByIdPrefix(string $runId, string $idPrefix): array
    {
        $idPrefix = strtolower(trim($idPrefix));
        if ('' === $idPrefix) {
            return [];
        }

        if (64 === \strlen($idPrefix)) {
            $exact = $this->findReflection($runId, $idPrefix);

            return null === $exact ? [] : [$exact];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT reflection_id, run_id, content, supporting_observation_ids_json, token_count
             FROM om_reflection
             WHERE run_id = ? AND reflection_id LIKE ?
             ORDER BY reflection_id ASC
             LIMIT 3',
            [$runId, $idPrefix.'%'],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'reflection_id' => (string) ($row['reflection_id'] ?? ''),
                'run_id' => (string) ($row['run_id'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'supporting_observation_ids_json' => (string) ($row['supporting_observation_ids_json'] ?? '[]'),
                'token_count' => (int) ($row['token_count'] ?? 0),
            ];
        }

        return $out;
    }

    public function countReflectionsForRun(string $runId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(1) FROM om_reflection WHERE run_id = ?',
            [$runId],
        );

        return (int) $count;
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

        return $this->listReflectionsForGeneration($generationId);
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
    public function listReflectionsForGeneration(string $generationId): array
    {
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

    /**
     * @return list<string>
     */
    public function listRetainedObservationIds(string $generationId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT observation_id FROM om_generation_retained_observation
             WHERE generation_id = ?
             ORDER BY position ASC',
            [$generationId],
        );

        $out = [];
        foreach ($rows as $id) {
            if (\is_string($id) && '' !== $id) {
                $out[] = $id;
            }
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

    /**
     * Boundary threshold dispatch guard: suppress new job creation when this exact
     * observation set already has a running, succeeded, or failed generation.
     *
     * Failed remains reclaimable by Messenger redelivery of the same generation id
     * via claimGeneration; this only blocks re-dispatch from later observe boundaries.
     */
    public function hasTerminalOrInFlightGenerationForSet(string $runId, string $observationSetHash): bool
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(1) FROM om_memory_generation
             WHERE run_id = ? AND observation_set_hash = ? AND status IN (?, ?, ?)',
            [
                $runId,
                $observationSetHash,
                self::STATUS_RUNNING,
                self::STATUS_SUCCEEDED,
                self::STATUS_FAILED,
            ],
        );

        return (int) $count > 0;
    }

    /**
     * @return array{
     *   generation_id: string,
     *   run_id: string,
     *   trigger_kind: string,
     *   status: string,
     *   observation_set_hash: string,
     *   reflector_model: string,
     *   reflector_schema_version: string,
     *   threshold_idempotency_key: ?string,
     *   required_start_seq: ?int,
     *   required_end_seq: ?int,
     *   compaction_request_id: ?string,
     *   request_fingerprint: ?string,
     *   failure_code: ?string
     * }|null
     */
    public function getGeneration(string $generationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT generation_id, run_id, trigger_kind, status, observation_set_hash, reflector_model,
                    reflector_schema_version, threshold_idempotency_key, required_start_seq, required_end_seq,
                    compaction_request_id, request_fingerprint, failure_code
             FROM om_memory_generation WHERE generation_id = ?',
            [$generationId],
        );
        if (false === $row) {
            return null;
        }

        return [
            'generation_id' => (string) $row['generation_id'],
            'run_id' => (string) $row['run_id'],
            'trigger_kind' => (string) $row['trigger_kind'],
            'status' => (string) $row['status'],
            'observation_set_hash' => (string) $row['observation_set_hash'],
            'reflector_model' => (string) $row['reflector_model'],
            'reflector_schema_version' => (string) $row['reflector_schema_version'],
            'threshold_idempotency_key' => isset($row['threshold_idempotency_key'])
                ? (string) $row['threshold_idempotency_key']
                : null,
            'required_start_seq' => isset($row['required_start_seq'])
                ? (int) $row['required_start_seq']
                : null,
            'required_end_seq' => isset($row['required_end_seq'])
                ? (int) $row['required_end_seq']
                : null,
            'compaction_request_id' => isset($row['compaction_request_id'])
                ? (string) $row['compaction_request_id']
                : null,
            'request_fingerprint' => isset($row['request_fingerprint'])
                ? (string) $row['request_fingerprint']
                : null,
            'failure_code' => isset($row['failure_code'])
                ? (string) $row['failure_code']
                : null,
        ];
    }

    /**
     * Atomically claim a generation row for work.
     *
     * @return array{status: 'claimed'|'already_running'|'already_succeeded'|'conflict'|'failed_retryable'}
     */
    public function claimGeneration(
        string $generationId,
        string $runId,
        string $triggerKind,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        string $now,
        ?string $thresholdIdempotencyKey = null,
        ?int $requiredStartSeq = null,
        ?int $requiredEndSeq = null,
        ?string $compactionRequestId = null,
        ?string $requestFingerprint = null,
    ): array {
        $existing = $this->getGeneration($generationId);
        if (null !== $existing) {
            if (self::STATUS_SUCCEEDED === $existing['status']) {
                return ['status' => 'already_succeeded'];
            }
            if (self::STATUS_RUNNING === $existing['status']) {
                return ['status' => 'already_running'];
            }
            if (self::STATUS_FAILED === $existing['status']) {
                // Allow Messenger redelivery of a previously failed generation claim.
                $this->connection->executeStatement(
                    'UPDATE om_memory_generation
                     SET status = ?, failure_code = NULL, completed_at = NULL, created_at = ?
                     WHERE generation_id = ? AND status = ?',
                    [self::STATUS_RUNNING, $now, $generationId, self::STATUS_FAILED],
                );

                return ['status' => 'claimed'];
            }

            return ['status' => 'conflict'];
        }

        try {
            $this->connection->insert('om_memory_generation', [
                'generation_id' => $generationId,
                'run_id' => $runId,
                'trigger_kind' => $triggerKind,
                'status' => self::STATUS_RUNNING,
                'observation_set_hash' => $observationSetHash,
                'reflector_model' => $reflectorModel,
                'reflector_schema_version' => $reflectorSchemaVersion,
                'threshold_idempotency_key' => $thresholdIdempotencyKey,
                'required_start_seq' => $requiredStartSeq,
                'required_end_seq' => $requiredEndSeq,
                'compaction_request_id' => $compactionRequestId,
                'request_fingerprint' => $requestFingerprint,
                'failure_code' => null,
                'created_at' => $now,
                'completed_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            $again = $this->getGeneration($generationId);
            if (null === $again) {
                // Unique on threshold/compaction key may have raced with another generation id.
                if ($this->hasRunningOrSucceededForSet($runId, $observationSetHash)) {
                    return ['status' => 'already_running'];
                }

                return ['status' => 'conflict'];
            }
            if (self::STATUS_SUCCEEDED === $again['status']) {
                return ['status' => 'already_succeeded'];
            }
            if (self::STATUS_RUNNING === $again['status']) {
                return ['status' => 'already_running'];
            }

            return ['status' => 'conflict'];
        }

        return ['status' => 'claimed'];
    }

    /**
     * Commit a successful generation and promote active pointer in one transaction.
     *
     * @param list<array{
     *   reflection_id: string,
     *   content: string,
     *   supporting_observation_ids_json: string,
     *   token_count: int
     * }> $reflections
     * @param list<string> $retainedObservationIds
     *
     * @return array{status: 'inserted'|'noop'}
     */
    public function commitSucceededGeneration(
        string $generationId,
        string $runId,
        string $observationSetHash,
        string $reflectorModel,
        string $reflectorSchemaVersion,
        array $reflections,
        array $retainedObservationIds,
        string $now,
        ?string $compactionRequestId = null,
    ): array {
        $existing = $this->getGeneration($generationId);
        if (null === $existing) {
            throw new \RuntimeException(\sprintf('Generation %s not found for success commit.', $generationId));
        }
        if (self::STATUS_SUCCEEDED === $existing['status']) {
            return ['status' => 'noop'];
        }
        if (self::STATUS_RUNNING !== $existing['status']) {
            throw new OmConflictException(\sprintf('Generation %s is %s; cannot commit success.', $generationId, $existing['status']));
        }
        if ($existing['run_id'] !== $runId
            || $existing['observation_set_hash'] !== $observationSetHash
            || $existing['reflector_model'] !== $reflectorModel
            || $existing['reflector_schema_version'] !== $reflectorSchemaVersion) {
            throw new OmConflictException(\sprintf('Generation %s identity mismatch on success commit.', $generationId));
        }

        // Participate in an outer write transaction when the caller already holds one
        // (compaction late-timeout CAS + result commit must roll back together).
        $ownsTransaction = !$this->connection->isTransactionActive();
        if ($ownsTransaction) {
            $this->connection->beginTransaction();
        }
        try {
            foreach ($reflections as $position => $reflection) {
                try {
                    $this->connection->insert('om_reflection', [
                        'reflection_id' => $reflection['reflection_id'],
                        'run_id' => $runId,
                        'compaction_request_id' => $compactionRequestId ?? '',
                        'observation_set_hash' => $observationSetHash,
                        'content' => $reflection['content'],
                        'supporting_observation_ids_json' => $reflection['supporting_observation_ids_json'],
                        'compression_level' => '0',
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

                $this->connection->insert('om_generation_reflection', [
                    'generation_id' => $generationId,
                    'reflection_id' => $reflection['reflection_id'],
                    'position' => $position,
                ]);
            }

            foreach ($retainedObservationIds as $position => $observationId) {
                $this->connection->insert('om_generation_retained_observation', [
                    'generation_id' => $generationId,
                    'observation_id' => $observationId,
                    'position' => $position,
                ]);
            }

            $updated = $this->connection->executeStatement(
                'UPDATE om_memory_generation
                 SET status = ?, completed_at = ?, failure_code = NULL
                 WHERE generation_id = ? AND status = ?',
                [self::STATUS_SUCCEEDED, $now, $generationId, self::STATUS_RUNNING],
            );
            if (1 !== $updated) {
                throw new OmConflictException(\sprintf('Generation %s left running status before success commit.', $generationId));
            }

            $this->connection->executeStatement(
                'INSERT INTO om_active_generation (run_id, generation_id) VALUES (?, ?)
                 ON CONFLICT(run_id) DO UPDATE SET generation_id = excluded.generation_id',
                [$runId, $generationId],
            );

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

    public function markFailed(string $generationId, string $failureCode, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE om_memory_generation
             SET status = ?, failure_code = ?, completed_at = ?
             WHERE generation_id = ? AND status = ?',
            [self::STATUS_FAILED, $failureCode, $now, $generationId, self::STATUS_RUNNING],
        );
    }

    /**
     * Soft-complete a claimed generation without promoting active memory (threshold no-op).
     */
    public function markSucceededNoop(string $generationId, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE om_memory_generation
             SET status = ?, completed_at = ?, failure_code = NULL
             WHERE generation_id = ? AND status = ?',
            [self::STATUS_SUCCEEDED, $now, $generationId, self::STATUS_RUNNING],
        );
    }
}
