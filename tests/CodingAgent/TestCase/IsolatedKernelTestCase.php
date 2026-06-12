<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that need a booted Symfony kernel with an isolated
 * Hatfield CWD for filesystem artifacts (sessions, state/events/transcript files).
 *
 * Database isolation is handled by DAMA/DoctrineTestBundle:
 * the test DB is configured in config/packages/test/doctrine.yaml
 * with a fixed path, and DAMA's PHPUnitExtension wraps each test in
 * a transaction that is rolled back afterward. No per-test migrations
 * or schema rebuild are needed — the schema is created once before
 * the test suite runs.
 *
 * Setup flow per test:
 *  1. Creates an isolated cwd under var/tmp/<prefix>-<random>/ with .hatfield/
 *  2. chdir() into it so Kernel::boot() picks it up as HATFIELD_CWD
 *     for filesystem artifact paths (session directories, etc.)
 *  3. Boots the Symfony kernel in test environment
 *  4. Tests get services from static::getContainer()
 *
 * The test database path does NOT depend on the isolated cwd.
 * config/packages/test/doctrine.yaml overrides the DBAL path to a
 * fixed project-relative location, so DAMA can maintain a static
 * connection for transaction rollback between test methods.
 *
 * TearDown closes the EntityManager, restores original CWD,
 * shuts down kernel, and removes the isolated directory.
 */
abstract class IsolatedKernelTestCase extends KernelTestCase
{
    private string $isolatedCwd;
    private string|false $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        // Create isolated cwd with a .hatfield/ directory.
        // Each test method gets a unique directory for filesystem isolation.
        $this->isolatedCwd = TestDirectoryIsolation::createProjectTempDir('hatfield-test', 0o750);
        TestDirectoryIsolation::createHatfieldTree($this->isolatedCwd);

        // Save original cwd so we can restore it in tearDown.
        $this->originalCwd = getcwd();

        // chdir into isolated cwd BEFORE kernel boot.
        // Kernel::boot() reads getcwd() into HATFIELD_CWD env var.
        chdir($this->isolatedCwd);

        // Required env vars for container compilation.
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['APP_SECRET'] = 'test-secret';
        $_ENV['HATFIELD_CWD'] = $this->isolatedCwd;
        putenv('HATFIELD_CWD='.$this->isolatedCwd);

        // Boot without debug mode so Symfony ErrorHandler does not leave
        // exception handlers on PHPUnit's stack and mark tests risky.
        self::bootKernel(['environment' => 'test', 'debug' => false]);
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
        // Close the EntityManager before restoring CWD.
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
        if (isset($this->isolatedCwd) && is_dir($this->isolatedCwd)) {
            TestDirectoryIsolation::removeDirectory($this->isolatedCwd);
        }

        parent::tearDown();

        // Pop the exception handler that FrameworkBundle::boot() registered
        // during kernel boot/shutdown. KernelTestCase::tearDown() calls
        // ensureKernelShutdown() which may re-boot the kernel and re-register
        // the handler, so we must restore after parent::tearDown().
        restore_exception_handler();
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Returns the isolated project temp directory created during setUp().
     * Subclasses writing temp files (e.g. prompt templates) can use this
     * instead of reflection to find the isolated CWD.
     */
    protected function isolatedCwd(): string
    {
        return $this->isolatedCwd;
    }
}
