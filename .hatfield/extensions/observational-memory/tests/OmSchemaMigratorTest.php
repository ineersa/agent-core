<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmDatabaseFactoryTestService;
use Psr\Log\NullLogger;

/**
 * Thesis: migration 003 maps relevance/timestamps, rebuilds coverage parts, separates request_fingerprint,
 * and creates generation tables while preserving legacy rows.
 */
final class OmSchemaMigratorTest extends IsolatedKernelTestCase
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

    public function testMigrate003CreatesGenerationTablesAndAcceptsTextRelevance(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $connection = $this->omDatabaseFactory()->connectAndMigrate($dbPath, new NullLogger());

        $versions = $connection->fetchFirstColumn('SELECT version FROM om_schema_version');
        $this->assertContains('20260726_003_active_generation_and_relevance_text', $versions);
        $this->assertContains('20260729_004_current_activity', $versions);
        $this->assertTrue($connection->createSchemaManager()->tablesExist(['om_current_activity']));

        $columns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $connection->fetchAllAssociative('PRAGMA table_info(om_observation)'),
        );
        $this->assertContains('timestamp', $columns);
        $this->assertContains('relevance', $columns);

        $coverageColumns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $connection->fetchAllAssociative('PRAGMA table_info(om_coverage)'),
        );
        foreach (['chunk_key', 'part_index', 'part_count', 'part_digest'] as $col) {
            $this->assertContains($col, $coverageColumns);
        }

        $requestColumns = array_map(
            static fn (array $c): string => (string) $c['name'],
            $connection->fetchAllAssociative('PRAGMA table_info(om_compaction_request)'),
        );
        $this->assertContains('request_fingerprint', $requestColumns);

        foreach ([
            'om_memory_generation',
            'om_generation_reflection',
            'om_generation_retained_observation',
            'om_active_generation',
        ] as $table) {
            $this->assertTrue($connection->createSchemaManager()->tablesExist([$table]), $table);
        }

        $connection->insert('om_observation', [
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

        $connection->insert('om_compaction_request', [
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

        $row = $connection->fetchAssociative('SELECT relevance, timestamp FROM om_observation WHERE observation_id = ?', ['obs-1']);
        $this->assertSame('high', $row['relevance'] ?? null);
        $this->assertSame('2026-07-26 12:00', $row['timestamp'] ?? null);

        $req = $connection->fetchAssociative('SELECT request_fingerprint, observation_set_hash FROM om_compaction_request WHERE request_id = ?', ['req-1']);
        $this->assertSame('fp-1', $req['request_fingerprint'] ?? null);
        $this->assertTrue(!isset($req['observation_set_hash']) || null === $req['observation_set_hash'] || '' === $req['observation_set_hash']);
    }

    public function testLegacyIntegerRelevanceMapsDuringMigration(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/map.sqlite';
        $factory = $this->omDatabaseFactory();
        $connection = $factory->connectWithPre003Schema($dbPath, new NullLogger());

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
        $connection->insert('om_reflection', [
            'reflection_id' => 'ref-legacy',
            'run_id' => 'run-x',
            'compaction_request_id' => 'req-legacy',
            'observation_set_hash' => 'legacy-fp',
            'content' => 'preserved reflection',
            'supporting_observation_ids_json' => '["legacy-1"]',
            'compression_level' => '0',
            'token_count' => 3,
            'reflector_model' => 'p/m',
            'reflector_schema_version' => '1',
            'created_at' => '2026-07-20T15:30:00+00:00',
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
        $connection->insert('om_compaction_result', [
            'result_id' => 'res-legacy',
            'request_id' => 'req-legacy',
            'run_id' => 'run-x',
            'required_watermark' => 2,
            'observation_set_hash' => 'legacy-fp',
            'status' => 'succeeded',
            'replacement_text' => 'deterministic prior summary',
            'metadata_json' => null,
            'failure_code' => null,
            'failure_metadata_json' => null,
            'created_at' => '2026-07-20T15:30:00+00:00',
            'completed_at' => '2026-07-20T15:31:00+00:00',
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

        $factory->migrate($connection, new NullLogger());

        $obs = $connection->fetchAssociative('SELECT relevance, timestamp, content FROM om_observation WHERE observation_id = ?', ['legacy-1']);
        $this->assertSame('critical', $obs['relevance'] ?? null);
        $this->assertSame('2026-07-20 15:30', $obs['timestamp'] ?? null);
        $this->assertSame('legacy', $obs['content'] ?? null);

        $cov = $connection->fetchAssociative('SELECT chunk_key, part_index, part_count, part_digest FROM om_coverage WHERE coverage_key = ?', ['cov-legacy']);
        $this->assertSame('cov-legacy', $cov['chunk_key'] ?? null);
        $this->assertSame(1, (int) ($cov['part_index'] ?? 0));
        $this->assertSame(1, (int) ($cov['part_count'] ?? 0));
        $this->assertSame('digest', $cov['part_digest'] ?? null);

        $req = $connection->fetchAssociative('SELECT request_fingerprint, observation_set_hash FROM om_compaction_request WHERE request_id = ?', ['req-legacy']);
        $this->assertSame('legacy-fp', $req['request_fingerprint'] ?? null);
        $this->assertTrue(!isset($req['observation_set_hash']) || null === $req['observation_set_hash'] || '' === $req['observation_set_hash']);

        $reflection = $connection->fetchAssociative('SELECT content FROM om_reflection WHERE reflection_id = ?', ['ref-legacy']);
        $this->assertSame('preserved reflection', $reflection['content'] ?? null);

        $result = $connection->fetchAssociative('SELECT replacement_text, status FROM om_compaction_result WHERE result_id = ?', ['res-legacy']);
        $this->assertSame('deterministic prior summary', $result['replacement_text'] ?? null);
        $this->assertSame('succeeded', $result['status'] ?? null);

        $versions = $connection->fetchFirstColumn('SELECT version FROM om_schema_version');
        $this->assertContains('20260726_003_active_generation_and_relevance_text', $versions);
    }

    private function omDatabaseFactory(): OmDatabaseFactoryTestService
    {
        /** @var OmDatabaseFactoryTestService $service */
        $service = self::getContainer()->get('test.om_database_factory');

        return $service;
    }
}
