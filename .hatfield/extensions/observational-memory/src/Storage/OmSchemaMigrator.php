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

        $this->ensureMessengerMessagesTable();
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
     * Messenger operational queue table (Doctrine transport shape).
     * Created with auto_setup=false on the private transport.
     */
    private function ensureMessengerMessagesTable(): void
    {
        $this->connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                body CLOB NOT NULL,
                headers CLOB NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE INDEX IF NOT EXISTS IDX_OM_MESSENGER_QUEUE ON messenger_messages (queue_name, available_at, delivered_at, id)',
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
        ];
    }

    /**
     * @return array<string, string>
     */
    private function descriptions(): array
    {
        return [
            '20260722_001_domain' => 'OM domain tables: observation, coverage, reflection, compaction request/result',
        ];
    }
}
