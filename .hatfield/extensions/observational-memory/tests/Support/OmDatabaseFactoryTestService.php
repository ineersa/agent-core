<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

use Doctrine\DBAL\Connection;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabaseFactory;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TEST-ONLY wrapper around production OmDatabaseFactory for container access.
 *
 * Registered only in config/services_test.yaml. Does not live in production src.
 * Also prepares exact pre-003 fixture schemas for migration preservation proofs.
 */
final class OmDatabaseFactoryTestService
{
    public function connect(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        return OmDatabaseFactory::connect($databasePath, $logger ?? new NullLogger());
    }

    public function connectAndMigrate(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        return OmDatabaseFactory::connectAndMigrate($databasePath, $logger ?? new NullLogger());
    }

    /**
     * Open a production-path SQLite connection and install the exact pre-003 schema
     * (001 domain + 002 multi-reflection shape) without running migration 003.
     */
    public function connectWithPre003Schema(string $databasePath, ?LoggerInterface $logger = null): Connection
    {
        $connection = $this->connect($databasePath, $logger);
        $this->installPre003Schema($connection);

        return $connection;
    }

    /**
     * Apply production OmSchemaMigrator (including 003) to an already-open connection.
     */
    public function migrate(Connection $connection, ?LoggerInterface $logger = null): void
    {
        (new OmSchemaMigrator($connection, $logger ?? new NullLogger()))->migrate();
    }

    private function installPre003Schema(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_schema_version (
            version TEXT PRIMARY KEY NOT NULL,
            description TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_observation (
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
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_coverage (
            coverage_key TEXT PRIMARY KEY NOT NULL,
            run_id TEXT NOT NULL,
            boundary_key TEXT NOT NULL,
            source_start_seq INTEGER NOT NULL,
            source_end_seq INTEGER NOT NULL,
            source_digest TEXT NOT NULL,
            renderer_version TEXT NOT NULL,
            observer_schema_version TEXT NOT NULL,
            observation_count INTEGER NOT NULL,
            covered_at TEXT NOT NULL
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_reflection (
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
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_compaction_request (
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
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS om_compaction_result (
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
        )');
    }
}
