<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Ineersa\CodingAgent\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Base class for tests that need a booted Symfony kernel with an isolated
 * Hatfield CWD and a fresh SQLite test database.
 *
 * Setup flow per test:
 *  1. Creates an isolated cwd under var/tests/<unique>/ with .hatfield/
 *  2. chdir() into it so Kernel::boot() picks it up as HATFIELD_CWD
 *  3. Sets required env vars (APP_ENV=test, APP_SECRET, messenger transports)
 *  4. Boots the kernel — Doctrine DB path %app.cwd%/.hatfield/messenger.sqlite
 *     resolves to the isolated cwd
 *  5. Runs doctrine:migrations:migrate against the fresh DB
 *  6. Tests get services from self::getContainer()
 *
 * TearDown restores original cwd, shuts down kernel, and removes the
 * isolated directory.
 *
 * This replaces manual DriverManager + ORMSetup + SchemaTool setups.
 */
abstract class IsolatedKernelTestCase extends KernelTestCase
{
    private string $isolatedCwd;
    private string|false $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = $this->resolveProjectRoot();

        // Create isolated cwd with a .hatfield/ directory.
        // Each test method gets a unique directory — no cross-test contamination.
        $this->isolatedCwd = $projectRoot.'/var/tests/hatfield-test-'.bin2hex(random_bytes(8));
        mkdir($this->isolatedCwd, 0750, true);
        mkdir($this->isolatedCwd.'/.hatfield', 0750, true);

        // Save original cwd so we can restore it in tearDown.
        $this->originalCwd = getcwd();

        // chdir into isolated cwd BEFORE kernel boot.
        // Kernel::boot() reads getcwd() into HATFIELD_CWD env var.
        chdir($this->isolatedCwd);

        // Required env vars for container compilation.
        // Transport DSNs default to sync:// — our store/manager tests do not
        // exercise the Messenger runtime, only Doctrine-backed stores.
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['APP_SECRET'] = 'test-secret';
        $_ENV['HATFIELD_RUN_CONTROL_TRANSPORT_DSN'] = 'sync://';
        $_ENV['HATFIELD_LLM_TRANSPORT_DSN'] = 'sync://';
        $_ENV['HATFIELD_TOOL_TRANSPORT_DSN'] = 'sync://';
        $_ENV['HATFIELD_CWD'] = $this->isolatedCwd;
        putenv('HATFIELD_CWD='.$this->isolatedCwd);

        // Boot the Symfony kernel in test environment.
        // Container compilation happens on first boot; cached on subsequent tests.
        // createKernel() is overridden below to avoid requiring KERNEL_CLASS env var.
        // debug=true prevents container caching — each test gets a fresh
        // container with the correct HATFIELD_CWD for its isolated cwd.
        self::bootKernel(['environment' => 'test', 'debug' => true]);

        // Create schema from entity metadata on the fresh isolated DB.
        $this->runMigrations();
    }

    /**
     * Override so subclasses don't need KERNEL_CLASS in phpunit.xml.dist.
     */
    protected static function createKernel(array $options = []): Kernel
    {
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = (bool) ($options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? false);

        return new Kernel($env, $debug);
    }

    protected function tearDown(): void
    {
        // Restore original CWD before shutting down the kernel.
        // Kernel shutdown may trigger cleanup that depends on CWD.
        if (false !== $this->originalCwd) {
            chdir($this->originalCwd);
        }

        if (self::$booted) {
            self::ensureKernelShutdown();
        }

        // Clean up the isolated directory tree.
        // Must happen after kernel shutdown so the kernel no longer holds
        // open file handles or locks on messenger.sqlite.
        if (isset($this->isolatedCwd) && is_dir($this->isolatedCwd)) {
            $this->removeDirectory($this->isolatedCwd);
        }

        parent::tearDown();
    }

    // ─── Migration helper ────────────────────────────────────────────

    /**
     * Run all pending Doctrine migrations against the isolated test database.
     *
     * Uses the kernel's Console Application to invoke the built-in
     * doctrine:migrations:migrate command. This is the same mechanism
     * StartupDatabaseMigrator uses in production.
     */
    private function runMigrations(): void
    {
        // Ensure the SQLite database file exists before schema commands run.
        // For file-based SQLite, Doctrine creates the file automatically,
        // but explicit touch() ensures the directory is writable.
        $dbPath = $this->isolatedCwd.'/.hatfield/messenger.sqlite';
        if (!file_exists($dbPath)) {
            touch($dbPath);
        }

        $application = new Application(static::$kernel);

        // Create schema from entity metadata. This is the standard Symfony
        // testing approach — produce DDL for the current entity state against
        // an empty database. Equivalent to migrations:migrate for a fresh DB
        // but does not require the migration version table or history.
        $input = new ArrayInput([
            'command' => 'doctrine:schema:create',
            '--no-interaction' => true,
            '-q' => true,
        ]);
        $exitCode = $application->doRun($input, new NullOutput());

        if (0 !== $exitCode) {
            throw new \RuntimeException(
                \sprintf('doctrine:schema:create failed with exit code %d.', $exitCode),
            );
        }
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Resolve the project root from __DIR__ (tests/CodingAgent/TestCase/ → project root).
     */
    private function resolveProjectRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * Recursively remove a directory tree.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir((string) $file);
            } else {
                unlink((string) $file);
            }
        }

        rmdir($dir);
    }
}
