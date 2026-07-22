<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabase;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmSchemaMigrator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Thesis: OM migrations create domain + messenger tables in om.sqlite only
 * and are idempotent on re-run.
 */
final class OmSchemaMigratorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/om-schema-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            $file->isDir() ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tmpDir);
    }

    public function testMigrateCreatesDomainAndMessengerTablesIdempotently(): void
    {
        $dbPath = $this->tmpDir.'/om.sqlite';
        $database = OmDatabase::connect($dbPath);
        $migrator = new OmSchemaMigrator($database->connection(), new NullLogger());

        $migrator->migrate();
        $migrator->migrate();

        $schema = $database->connection()->createSchemaManager();
        foreach ([
            'om_schema_version',
            'om_observation',
            'om_coverage',
            'om_reflection',
            'om_compaction_request',
            'om_compaction_result',
            'messenger_messages',
        ] as $table) {
            $this->assertTrue($schema->tablesExist([$table]), $table.' should exist');
        }

        $versions = $database->connection()->fetchFirstColumn('SELECT version FROM om_schema_version');
        $this->assertContains('20260722_001_domain', $versions);
        $this->assertFileExists($dbPath);
    }
}
