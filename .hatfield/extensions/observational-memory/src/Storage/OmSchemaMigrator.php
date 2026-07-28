<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Storage;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Explicit ordered migrations for the OM SQLite database.
 *
 * Extension-local metadata table only — never touches Hatfield
 * doctrine_migration_versions or host DBs.
 */
final class OmSchemaMigrator
{
    private const string VERSION_TABLE = 'om_schema_version';

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function migrate(): void
    {
        $this->ensureVersionTable();
        $applied = $this->appliedVersions();

        foreach ($this->migrations() as $version => $sqlStatements) {
            if (isset($applied[$version])) {
                continue;
            }

            $this->connection->beginTransaction();
            try {
                foreach ($sqlStatements as $sql) {
                    $this->connection->executeStatement($sql);
                }
                $this->connection->insert(self::VERSION_TABLE, [
                    'version' => $version,
                    'description' => $this->descriptions()[$version] ?? $version,
                    'checksum' => hash('sha256', implode("\n", $sqlStatements)),
                    'applied_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                ]);
                $this->connection->commit();
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }

            $this->logger->info('om.schema.migrated', [
                'component' => 'observational_memory',
                'event_type' => 'om.schema.migrated',
                'version' => $version,
            ]);
        }
    }

    /**
     * @return array<string, true>
     */
    private function appliedVersions(): array
    {
        $rows = $this->connection->fetchFirstColumn('SELECT version FROM '.self::VERSION_TABLE);
        $map = [];
        foreach ($rows as $version) {
            if (\is_string($version)) {
                $map[$version] = true;
            }
        }

        return $map;
    }

    private function ensureVersionTable(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS '.self::VERSION_TABLE.' (
                version TEXT PRIMARY KEY NOT NULL,
                description TEXT NOT NULL,
                checksum TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function migrations(): array
    {
        return [
            '20260722_001_domain' => [
                'CREATE TABLE IF NOT EXISTS om_observation (
                    observation_id TEXT PRIMARY KEY NOT NULL,
                    run_id TEXT NOT NULL,
                    boundary_key TEXT NOT NULL,
                    source_start_seq INTEGER NOT NULL,
                    source_end_seq INTEGER NOT NULL,
                    source_refs_json TEXT NOT NULL,
                    content TEXT NOT NULL,
                    content_hash TEXT NOT NULL,
                    relevance INTEGER NOT NULL,
                    token_count INTEGER NOT NULL,
                    observer_model TEXT NOT NULL,
                    observer_schema_version TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )',
                'CREATE INDEX IF NOT EXISTS idx_om_observation_run_created ON om_observation (run_id, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_om_observation_run_boundary ON om_observation (run_id, boundary_key)',
                'CREATE TABLE IF NOT EXISTS om_coverage (
                    coverage_key TEXT PRIMARY KEY NOT NULL,
                    run_id TEXT NOT NULL,
                    boundary_key TEXT NOT NULL,
                    source_start_seq INTEGER NOT NULL,
                    source_end_seq INTEGER NOT NULL,
                    source_digest TEXT NOT NULL,
                    renderer_version TEXT NOT NULL,
                    observer_schema_version TEXT NOT NULL,
                    observation_count INTEGER NOT NULL,
                    covered_at TEXT NOT NULL,
                    UNIQUE (run_id, boundary_key, renderer_version, observer_schema_version)
                )',
                'CREATE TABLE IF NOT EXISTS om_reflection (
                    reflection_id TEXT PRIMARY KEY NOT NULL,
                    run_id TEXT NOT NULL,
                    compaction_request_id TEXT NOT NULL,
                    observation_set_hash TEXT NOT NULL,
                    content TEXT NOT NULL,
                    supporting_observation_ids_json TEXT NOT NULL,
                    compression_level TEXT NOT NULL,
                    token_count INTEGER NOT NULL,
                    reflector_model TEXT NOT NULL,
                    reflector_schema_version TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE (compaction_request_id, reflector_schema_version)
                )',
                'CREATE TABLE IF NOT EXISTS om_compaction_request (
                    request_id TEXT PRIMARY KEY NOT NULL,
                    run_id TEXT NOT NULL,
                    required_start_seq INTEGER NOT NULL,
                    required_end_seq INTEGER NOT NULL,
                    required_watermark INTEGER NOT NULL,
                    observation_set_hash TEXT NOT NULL,
                    status TEXT NOT NULL,
                    requested_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    completed_at TEXT DEFAULT NULL,
                    failure_code TEXT DEFAULT NULL,
                    failure_metadata_json TEXT DEFAULT NULL
                )',
                'CREATE TABLE IF NOT EXISTS om_compaction_result (
                    result_id TEXT PRIMARY KEY NOT NULL,
                    request_id TEXT NOT NULL UNIQUE,
                    run_id TEXT NOT NULL,
                    required_watermark INTEGER NOT NULL,
                    observation_set_hash TEXT NOT NULL,
                    status TEXT NOT NULL,
                    replacement_text TEXT DEFAULT NULL,
                    metadata_json TEXT DEFAULT NULL,
                    failure_code TEXT DEFAULT NULL,
                    failure_metadata_json TEXT DEFAULT NULL,
                    created_at TEXT NOT NULL,
                    completed_at TEXT DEFAULT NULL
                )',
            ],
            // Fix UNIQUE(request, schema) so multiple reflections per request are possible,
            // and add contiguous coverage / observation / reflection lookup indexes.
            '20260725_002_reflection_multi_and_indexes' => [
                'CREATE TABLE om_reflection_new (
                    reflection_id TEXT PRIMARY KEY NOT NULL,
                    run_id TEXT NOT NULL,
                    compaction_request_id TEXT NOT NULL,
                    observation_set_hash TEXT NOT NULL,
                    content TEXT NOT NULL,
                    supporting_observation_ids_json TEXT NOT NULL,
                    compression_level TEXT NOT NULL,
                    token_count INTEGER NOT NULL,
                    reflector_model TEXT NOT NULL,
                    reflector_schema_version TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )',
                'INSERT INTO om_reflection_new (
                    reflection_id, run_id, compaction_request_id, observation_set_hash, content,
                    supporting_observation_ids_json, compression_level, token_count,
                    reflector_model, reflector_schema_version, created_at
                )
                SELECT reflection_id, run_id, compaction_request_id, observation_set_hash, content,
                       supporting_observation_ids_json, compression_level, token_count,
                       reflector_model, reflector_schema_version, created_at
                FROM om_reflection',
                'DROP TABLE om_reflection',
                'ALTER TABLE om_reflection_new RENAME TO om_reflection',
                'CREATE INDEX IF NOT EXISTS idx_om_reflection_request ON om_reflection (compaction_request_id, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_om_reflection_run ON om_reflection (run_id, created_at)',
                'CREATE INDEX IF NOT EXISTS idx_om_coverage_run_versions_range ON om_coverage (run_id, renderer_version, observer_schema_version, source_start_seq, source_end_seq)',
                'CREATE INDEX IF NOT EXISTS idx_om_observation_run_range ON om_observation (run_id, source_start_seq, source_end_seq)',
                'CREATE INDEX IF NOT EXISTS idx_om_compaction_request_run_status ON om_compaction_request (run_id, status, updated_at)',
            ],
            '20260726_003_active_generation_and_relevance_text' => $this->migration003Statements(),
        ];
    }

    /**
     * @return list<string>
     */
    private function migration003Statements(): array
    {
        return [
            // Observation: relevance TEXT + timestamp TEXT, one-time integer map.
            'CREATE TABLE om_observation_new (
                observation_id TEXT PRIMARY KEY NOT NULL,
                run_id TEXT NOT NULL,
                boundary_key TEXT NOT NULL,
                source_start_seq INTEGER NOT NULL,
                source_end_seq INTEGER NOT NULL,
                source_refs_json TEXT NOT NULL,
                content TEXT NOT NULL,
                content_hash TEXT NOT NULL,
                relevance TEXT NOT NULL CHECK (relevance IN (\'low\',\'medium\',\'high\',\'critical\')),
                timestamp TEXT NOT NULL CHECK (timestamp GLOB \'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]\'),
                token_count INTEGER NOT NULL,
                observer_model TEXT NOT NULL,
                observer_schema_version TEXT NOT NULL,
                created_at TEXT NOT NULL
            )',
            "INSERT INTO om_observation_new (
                observation_id, run_id, boundary_key, source_start_seq, source_end_seq, source_refs_json,
                content, content_hash, relevance, timestamp, token_count, observer_model, observer_schema_version, created_at
            )
            SELECT
                observation_id,
                run_id,
                boundary_key,
                source_start_seq,
                source_end_seq,
                source_refs_json,
                content,
                content_hash,
                CASE
                    WHEN relevance <= 24 THEN 'low'
                    WHEN relevance <= 49 THEN 'medium'
                    WHEN relevance <= 74 THEN 'high'
                    ELSE 'critical'
                END,
                CASE
                    WHEN substr(replace(created_at, 'T', ' '), 1, 16) GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9] [0-9][0-9]:[0-9][0-9]'
                        THEN substr(replace(created_at, 'T', ' '), 1, 16)
                    ELSE '1970-01-01 00:00'
                END,
                token_count,
                observer_model,
                observer_schema_version,
                created_at
            FROM om_observation",
            'DROP TABLE om_observation',
            'ALTER TABLE om_observation_new RENAME TO om_observation',
            'CREATE INDEX IF NOT EXISTS idx_om_observation_run_created ON om_observation (run_id, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_om_observation_run_boundary ON om_observation (run_id, boundary_key)',
            'CREATE INDEX IF NOT EXISTS idx_om_observation_run_range ON om_observation (run_id, source_start_seq, source_end_seq)',
            'CREATE INDEX IF NOT EXISTS idx_om_observation_run_timestamp ON om_observation (run_id, timestamp, observation_id)',

            // Coverage rebuild with chunk/part columns.
            'CREATE TABLE om_coverage_new (
                coverage_key TEXT PRIMARY KEY NOT NULL,
                run_id TEXT NOT NULL,
                boundary_key TEXT NOT NULL,
                source_start_seq INTEGER NOT NULL CHECK (source_start_seq >= 1),
                source_end_seq INTEGER NOT NULL CHECK (source_end_seq >= source_start_seq),
                chunk_key TEXT NOT NULL,
                part_index INTEGER NOT NULL CHECK (part_index >= 1),
                part_count INTEGER NOT NULL CHECK (part_count >= part_index),
                source_digest TEXT NOT NULL,
                part_digest TEXT NOT NULL,
                renderer_version TEXT NOT NULL,
                observer_schema_version TEXT NOT NULL,
                observation_count INTEGER NOT NULL CHECK (observation_count >= 0),
                covered_at TEXT NOT NULL,
                UNIQUE (run_id, chunk_key, part_index, renderer_version, observer_schema_version)
            )',
            'INSERT INTO om_coverage_new (
                coverage_key, run_id, boundary_key, source_start_seq, source_end_seq,
                chunk_key, part_index, part_count, source_digest, part_digest,
                renderer_version, observer_schema_version, observation_count, covered_at
            )
            SELECT
                coverage_key,
                run_id,
                boundary_key,
                source_start_seq,
                source_end_seq,
                coverage_key,
                1,
                1,
                source_digest,
                source_digest,
                renderer_version,
                observer_schema_version,
                observation_count,
                covered_at
            FROM om_coverage',
            'DROP TABLE om_coverage',
            'ALTER TABLE om_coverage_new RENAME TO om_coverage',
            'CREATE INDEX IF NOT EXISTS idx_om_coverage_run_versions_range ON om_coverage (run_id, renderer_version, observer_schema_version, source_start_seq, source_end_seq)',
            'CREATE INDEX IF NOT EXISTS idx_om_coverage_run_chunk_part ON om_coverage (run_id, chunk_key, part_index)',

            // Compaction request: separate request_fingerprint; observation_set_hash nullable.
            'CREATE TABLE om_compaction_request_new (
                request_id TEXT PRIMARY KEY NOT NULL,
                run_id TEXT NOT NULL,
                required_start_seq INTEGER NOT NULL,
                required_end_seq INTEGER NOT NULL,
                required_watermark INTEGER NOT NULL,
                request_fingerprint TEXT NOT NULL,
                observation_set_hash TEXT DEFAULT NULL,
                status TEXT NOT NULL,
                requested_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT DEFAULT NULL,
                failure_code TEXT DEFAULT NULL,
                failure_metadata_json TEXT DEFAULT NULL
            )',
            // Legacy overloading: observation_set_hash held the request fingerprint.
            'INSERT INTO om_compaction_request_new (
                request_id, run_id, required_start_seq, required_end_seq, required_watermark,
                request_fingerprint, observation_set_hash, status, requested_at, updated_at,
                completed_at, failure_code, failure_metadata_json
            )
            SELECT
                request_id,
                run_id,
                required_start_seq,
                required_end_seq,
                required_watermark,
                observation_set_hash,
                NULL,
                status,
                requested_at,
                updated_at,
                completed_at,
                failure_code,
                failure_metadata_json
            FROM om_compaction_request',
            'DROP TABLE om_compaction_request',
            'ALTER TABLE om_compaction_request_new RENAME TO om_compaction_request',
            'CREATE INDEX IF NOT EXISTS idx_om_compaction_request_run_status ON om_compaction_request (run_id, status, updated_at)',

            // Active generation tables.
            'CREATE TABLE IF NOT EXISTS om_memory_generation (
                generation_id TEXT PRIMARY KEY NOT NULL,
                run_id TEXT NOT NULL,
                trigger_kind TEXT NOT NULL CHECK (trigger_kind IN (\'threshold\',\'compaction\')),
                status TEXT NOT NULL CHECK (status IN (\'running\',\'succeeded\',\'failed\')),
                observation_set_hash TEXT NOT NULL,
                reflector_model TEXT NOT NULL,
                reflector_schema_version TEXT NOT NULL,
                threshold_idempotency_key TEXT DEFAULT NULL,
                required_start_seq INTEGER DEFAULT NULL,
                required_end_seq INTEGER DEFAULT NULL,
                compaction_request_id TEXT DEFAULT NULL,
                request_fingerprint TEXT DEFAULT NULL,
                failure_code TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                completed_at TEXT DEFAULT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_om_memory_generation_run_created ON om_memory_generation (run_id, created_at)',
            'CREATE INDEX IF NOT EXISTS idx_om_memory_generation_run_set_status ON om_memory_generation (run_id, observation_set_hash, status)',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_om_memory_generation_threshold_key ON om_memory_generation (threshold_idempotency_key) WHERE threshold_idempotency_key IS NOT NULL',
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_om_memory_generation_compaction ON om_memory_generation (compaction_request_id, reflector_schema_version, reflector_model) WHERE compaction_request_id IS NOT NULL',
            'CREATE TABLE IF NOT EXISTS om_generation_reflection (
                generation_id TEXT NOT NULL,
                reflection_id TEXT NOT NULL,
                position INTEGER NOT NULL,
                PRIMARY KEY (generation_id, reflection_id),
                UNIQUE (generation_id, position),
                FOREIGN KEY (generation_id) REFERENCES om_memory_generation(generation_id)
            )',
            'CREATE TABLE IF NOT EXISTS om_generation_retained_observation (
                generation_id TEXT NOT NULL,
                observation_id TEXT NOT NULL,
                position INTEGER NOT NULL,
                PRIMARY KEY (generation_id, observation_id),
                UNIQUE (generation_id, position),
                FOREIGN KEY (generation_id) REFERENCES om_memory_generation(generation_id)
            )',
            'CREATE TABLE IF NOT EXISTS om_active_generation (
                run_id TEXT PRIMARY KEY NOT NULL,
                generation_id TEXT NOT NULL,
                FOREIGN KEY (generation_id) REFERENCES om_memory_generation(generation_id)
            )',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        return [
            '20260722_001_domain' => 'OM domain tables: observation, coverage, reflection, compaction request/result',
            '20260725_002_reflection_multi_and_indexes' => 'Allow multiple reflections per request; add coverage/observation/request indexes',
            '20260726_003_active_generation_and_relevance_text' => 'Relevance TEXT + timestamp; chunk/part coverage; request_fingerprint; active generation tables',
        ];
    }
}
