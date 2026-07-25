<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: OM migrations create domain tables in om.sqlite only, remain idempotent,
 * and migration 002 preserves reflections while allowing multiple per request.
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

    public function testMigrateCreatesDomainTablesIdempotentlyAndSupportsMultipleReflections(): void
    {
        $dbPath = $this->projectDir.'/.hatfield/extensions-data/observational-memory/om.sqlite';
        $database = OmTestDatabase::connect($dbPath);
        $connection = $database->connection();
        $migrator = new OmSchemaMigrator($connection, new NullLogger());

        $migrator->migrate();
        $migrator->migrate();

        $schema = $connection->createSchemaManager();
        foreach ([
            'om_schema_version',
            'om_observation',
            'om_coverage',
            'om_reflection',
            'om_compaction_request',
            'om_compaction_result',
        ] as $table) {
            $this->assertTrue($schema->tablesExist([$table]), $table.' should exist');
        }

        $this->assertFalse($schema->tablesExist(['messenger_messages']), 'OM no longer owns Messenger tables');

        $versions = $connection->fetchFirstColumn('SELECT version FROM om_schema_version');
        $this->assertContains('20260722_001_domain', $versions);
        $this->assertContains('20260725_002_reflection_multi_and_indexes', $versions);

        // Multiple reflections per request must be allowed after migration 002.
        $connection->insert('om_reflection', [
            'reflection_id' => 'r1',
            'run_id' => 'run-1',
            'compaction_request_id' => 'req-1',
            'observation_set_hash' => 'h',
            'content' => 'one',
            'supporting_observation_ids_json' => '[]',
            'compression_level' => '0',
            'token_count' => 1,
            'reflector_model' => 'p/m',
            'reflector_schema_version' => 'v1',
            'created_at' => '2026-07-25T00:00:00+00:00',
        ]);
        $connection->insert('om_reflection', [
            'reflection_id' => 'r2',
            'run_id' => 'run-1',
            'compaction_request_id' => 'req-1',
            'observation_set_hash' => 'h',
            'content' => 'two',
            'supporting_observation_ids_json' => '[]',
            'compression_level' => '0',
            'token_count' => 1,
            'reflector_model' => 'p/m',
            'reflector_schema_version' => 'v1',
            'created_at' => '2026-07-25T00:00:01+00:00',
        ]);
        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM om_reflection WHERE compaction_request_id = ?',
            ['req-1'],
        );
        $this->assertSame(2, $count);
        $this->assertFileExists($dbPath);
    }
}
