<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Doctrine\ORM\EntityManagerInterface;
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
 *  3. Sets APP_ENV=test, APP_SECRET for container compilation
 *  4. Boots the kernel — Doctrine DB path %app.cwd%/.hatfield/messenger.sqlite
 *     resolves to the isolated cwd
 *  5. Runs doctrine:migrations:migrate against the fresh DB
 *  6. Tests get services from self::getContainer()
 *
 * Messenger transports use in-memory:// via config/packages/test/messenger.yaml.
 * No HATFIELD_*_TRANSPORT_DSN env vars are needed.
 *
 * TearDown closes the EntityManager (releasing the SQLite file handle),
 * restores original CWD, shuts down kernel, and removes the isolated
 * directory. Because config/services_test.yaml omits registerShutdownHandler
 * from BackgroundProcessManager, no PHP shutdown functions query the
 * deleted database.
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
        // Messenger transport DSNs come from config/packages/test/messenger.yaml
        // (in-memory://), not from env vars.
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['APP_SECRET'] = 'test-secret';
        $_ENV['HATFIELD_CWD'] = $this->isolatedCwd;
        putenv('HATFIELD_CWD='.$this->isolatedCwd);

        // Boot the Symfony kernel in test environment.
        // debug=true prevents container caching — each test method gets a
        // fresh container with the correct HATFIELD_CWD for its isolated cwd.
        self::bootKernel(['environment' => 'test', 'debug' => true]);

        // Apply database schema via built-in doctrine:migrations:migrate.
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
        // Close the EntityManager before removing the isolated directory.
        // Doctrine holds an open file handle on the SQLite database; closing
        // the EM releases it so removeDirectory() can unlink the file.
        // (clear() is needed first to detach managed entities.)
        if (self::$booted && self::getContainer()->has('doctrine.orm.default_entity_manager')) {
            try {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
                $em->clear();
                $em->close();
            } catch (\Throwable) {
                // EM may already be closed; ignore cleanup errors.
            }
        }

        // Restore original CWD before shutting down the kernel.
        if (false !== $this->originalCwd) {
            chdir($this->originalCwd);
        }

        if (self::$booted) {
            self::ensureKernelShutdown();
        }

        // Clean up the isolated directory tree.
        // EntityManager is already closed; no open file handles.
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
     * doctrine:migrations:migrate command, identical to the production
     * path through StartupDatabaseMigrator / AgentCommand startup.
     *
     * Intentionally uses the built-in migration command rather than
     * doctrine:schema:create so tests exercise the same schema-creation
     * path as production.
     */
    private function runMigrations(): void
    {
        $application = new Application(static::$kernel);

        $input = new ArrayInput([
            'command' => 'doctrine:migrations:migrate',
            '--no-interaction' => true,
            '--allow-no-migration' => true,
            '-q' => true,
        ]);
        $exitCode = $application->doRun($input, new NullOutput());

        if (0 !== $exitCode) {
            throw new \RuntimeException(
                \sprintf('doctrine:migrations:migrate failed with exit code %d.', $exitCode),
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
