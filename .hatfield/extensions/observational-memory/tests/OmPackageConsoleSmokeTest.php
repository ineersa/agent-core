<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Thesis: package bin/console boots independently of Hatfield and can migrate
 * a private SQLite DB plus list messenger commands.
 */
final class OmPackageConsoleSmokeTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/om-console-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir.'/db', 0750, true);
        mkdir($this->tmpDir.'/cache', 0750, true);
        mkdir($this->tmpDir.'/log', 0750, true);
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

    public function testListAndMigrateAgainstTempDatabase(): void
    {
        $packageRoot = \dirname(__DIR__);
        $console = $packageRoot.'/bin/console';
        $this->assertFileExists($console);

        $php = (new PhpExecutableFinder())->find(false) ?: \PHP_BINARY;
        $dbPath = $this->tmpDir.'/db/om.sqlite';
        $env = [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => 'om-test-secret',
            'OM_DATABASE_PATH' => $dbPath,
            'OM_PARENT_PID' => '0',
            'OM_CACHE_DIR' => $this->tmpDir.'/cache',
            'OM_LOG_DIR' => $this->tmpDir.'/log',
        ];

        $list = new Process([$php, $console, 'list', '--raw'], cwd: $packageRoot, env: $env, timeout: 60);
        $list->run();
        $this->assertTrue($list->isSuccessful(), $list->getErrorOutput().$list->getOutput());
        $output = $list->getOutput();
        $this->assertStringContainsString('om:migrate', $output);
        $this->assertStringContainsString('messenger:consume', $output);
        $this->assertStringContainsString('messenger:setup-transports', $output);

        $migrate = new Process([$php, $console, 'om:migrate', '--no-interaction'], cwd: $packageRoot, env: $env, timeout: 60);
        $migrate->run();
        $this->assertTrue($migrate->isSuccessful(), $migrate->getErrorOutput().$migrate->getOutput());
        $this->assertFileExists($dbPath);

        $pdo = new \PDO('sqlite:'.$dbPath);
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['om_schema_version', 'om_observation', 'om_coverage', 'messenger_messages'] as $table) {
            $this->assertContains($table, $tables, $table.' missing after om:migrate');
        }
    }
}
