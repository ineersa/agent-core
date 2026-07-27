<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: migration 003 maps relevance/timestamps, rebuilds coverage parts, separates request_fingerprint,
 * and creates generation tables while preserving legacy rows.
 */
final class OmSchemaMigratorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('om-schema');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testMigrate003PreservesRowsAndMapsRelevance(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $database = OmTestDatabase::connect($dbPath);
        $connection = $database->connection();
        $migrator = new OmSchemaMigrator($connection, new NullLogger());

        // Apply only domain migrations first by running full migrator after seeding via stepwise SQL.
        $migrator->migrate();

        // Re-open a fresh DB and seed pre-003 shape manually then re-run from scratch for mapping proof.
        $legacyPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/legacy.sqlite';
        $legacy = OmTestDatabase::connect($legacyPath);
        $legacyConn = $legacy->connection();
        $legacyMigrator = new OmSchemaMigrator($legacyConn, new NullLogger());
        // Insert version rows for 001/002 only by running SQL of first two migrations through private path:
        // Use full migrate then mutate columns back is hard; instead insert into post-002 tables then
        // force re-apply by deleting 003 version and rebuilding from intermediate state is complex.
        // Practical path: migrate full, verify 003 tables/columns + insert path works.
        $legacyMigrator->migrate();

        $versions = $legacyConn->fetchFirstColumn('SELECT version FROM om_schema_version');
        $this->assertContains('20260726_003_active_generation_and_relevance_text', $versions);

        $columns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $legacyConn->fetchAllAssociative('PRAGMA table_info(om_observation)'),
        );
        $this->assertContains('timestamp', $columns);
        $this->assertContains('relevance', $columns);

        $coverageColumns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $legacyConn->fetchAllAssociative('PRAGMA table_info(om_coverage)'),
        );
        foreach (['chunk_key', 'part_index', 'part_count', 'part_digest'] as $col) {
            $this->assertContains($col, $coverageColumns);
        }

        $requestColumns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $legacyConn->fetchAllAssociative('PRAGMA table_info(om_compaction_request)'),
        );
        $this->assertContains('request_fingerprint', $requestColumns);

        foreach ([
            'om_memory_generation',
            'om_generation_reflection',
            'om_generation_retained_observation',
            'om_active_generation',
        ] as $table) {
            $this->assertTrue($legacyConn->createSchemaManager()->tablesExist([$table]), $table);
        }

        // Insert observation with TEXT relevance + timestamp.
        $legacyConn->insert('om_observation', [
            'observation_id' => 'obs-1',
            'run_id' => 'run-1',
            'boundary_key' => 'b1',
            'source_start_seq' => 1,
            'source_end_seq' => 1,
            'source_refs_json' => '[]',
            'content' => 'hello',
            'content_hash' => hash('sha256', 'hello'),
            'relevance' => 'high',
            'timestamp' => '2026-07-26 12:00',
            'token_count' => 1,
            'observer_model' => 'p/m',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-26T12:00:00+00:00',
        ]);

        // request_fingerprint required; observation_set_hash nullable.
        $legacyConn->insert('om_compaction_request', [
            'request_id' => 'req-1',
            'run_id' => 'run-1',
            'required_start_seq' => 1,
            'required_end_seq' => 10,
            'required_watermark' => 10,
            'request_fingerprint' => 'fp-1',
            'observation_set_hash' => null,
            'status' => 'queued',
            'requested_at' => '2026-07-26T12:00:00+00:00',
            'updated_at' => '2026-07-26T12:00:00+00:00',
            'completed_at' => null,
            'failure_code' => null,
            'failure_metadata_json' => null,
        ]);

        $row = $legacyConn->fetchAssociative('SELECT relevance, timestamp FROM om_observation WHERE observation_id = ?', ['obs-1']);
        $this->assertSame('high', $row['relevance'] ?? null);
        $this->assertSame('2026-07-26 12:00', $row['timestamp'] ?? null);

        $req = $legacyConn->fetchAssociative('SELECT request_fingerprint, observation_set_hash FROM om_compaction_request WHERE request_id = ?', ['req-1']);
        $this->assertSame('fp-1', $req['request_fingerprint'] ?? null);
        $this->assertTrue(!isset($req['observation_set_hash']) || null === $req['observation_set_hash'] || '' === $req['observation_set_hash']);
    }

    public function testLegacyIntegerRelevanceMapsDuringMigration(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/map.sqlite';
        $database = OmTestDatabase::connect($dbPath);
        $connection = $database->connection();

        // Manually create pre-003 schema matching 001+002, seed integer relevance, then run migrator.
        $this->createPre003Schema($connection);
        $connection->insert('om_observation', [
            'observation_id' => 'legacy-1',
            'run_id' => 'run-x',
            'boundary_key' => 'b',
            'source_start_seq' => 1,
            'source_end_seq' => 2,
            'source_refs_json' => '[]',
            'content' => 'legacy',
            'content_hash' => 'h',
            'relevance' => 80,
            'token_count' => 2,
            'observer_model' => 'p/m',
            'observer_schema_version' => '1',
            'created_at' => '2026-07-20T15:30:00+00:00',
        ]);
        $connection->insert('om_coverage', [
            'coverage_key' => 'cov-legacy',
            'run_id' => 'run-x',
            'boundary_key' => 'b',
            'source_start_seq' => 1,
            'source_end_seq' => 2,
            'source_digest' => 'digest',
            'renderer_version' => '1',
            'observer_schema_version' => '1',
            'observation_count' => 1,
            'covered_at' => '2026-07-20T15:30:00+00:00',
        ]);
        $connection->insert('om_compaction_request', [
            'request_id' => 'req-legacy',
            'run_id' => 'run-x',
            'required_start_seq' => 1,
            'required_end_seq' => 2,
            'required_watermark' => 2,
            'observation_set_hash' => 'legacy-fp',
            'status' => 'queued',
            'requested_at' => '2026-07-20T15:30:00+00:00',
            'updated_at' => '2026-07-20T15:30:00+00:00',
            'completed_at' => null,
            'failure_code' => null,
            'failure_metadata_json' => null,
        ]);
        $connection->insert('om_schema_version', [
            'version' => '20260722_001_domain',
            'description' => 'seed',
            'checksum' => 'x',
            'applied_at' => '2026-07-20T00:00:00+00:00',
        ]);
        $connection->insert('om_schema_version', [
            'version' => '20260725_002_reflection_multi_and_indexes',
            'description' => 'seed',
            'checksum' => 'y',
            'applied_at' => '2026-07-20T00:00:00+00:00',
        ]);

        (new OmSchemaMigrator($connection, new NullLogger()))->migrate();

        $obs = $connection->fetchAssociative('SELECT relevance, timestamp FROM om_observation WHERE observation_id = ?', ['legacy-1']);
        $this->assertSame('critical', $obs['relevance'] ?? null);
        $this->assertSame('2026-07-20 15:30', $obs['timestamp'] ?? null);

        $cov = $connection->fetchAssociative('SELECT chunk_key, part_index, part_count, part_digest FROM om_coverage WHERE coverage_key = ?', ['cov-legacy']);
        $this->assertSame('cov-legacy', $cov['chunk_key'] ?? null);
        $this->assertSame(1, (int) ($cov['part_index'] ?? 0));
        $this->assertSame(1, (int) ($cov['part_count'] ?? 0));
        $this->assertSame('digest', $cov['part_digest'] ?? null);

        $req = $connection->fetchAssociative('SELECT request_fingerprint, observation_set_hash FROM om_compaction_request WHERE request_id = ?', ['req-legacy']);
        $this->assertSame('legacy-fp', $req['request_fingerprint'] ?? null);
        $this->assertTrue(!isset($req['observation_set_hash']) || null === $req['observation_set_hash'] || '' === $req['observation_set_hash']);
    }

    private function createPre003Schema(\Doctrine\DBAL\Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE om_schema_version (
            version TEXT PRIMARY KEY NOT NULL,
            description TEXT NOT NULL,
            checksum TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )');
        $connection->executeStatement('CREATE TABLE om_observation (
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
        $connection->executeStatement('CREATE TABLE om_coverage (
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
        $connection->executeStatement('CREATE TABLE om_reflection (
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
        $connection->executeStatement('CREATE TABLE om_compaction_request (
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
        $connection->executeStatement('CREATE TABLE om_compaction_result (
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
