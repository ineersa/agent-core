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
 * Kernel and CWD lifecycle (per-class, not per-method):
 *  1. setUpBeforeClass() creates ONE isolated CWD, chdir() into it,
 *     and boots the Symfony kernel once for the entire test class.
 *  2. setUp() ensures the CWD and env vars are still correct before
 *     each test method (in case a prior test changed them).
 *  3. tearDown() clears the EntityManager identity map but keeps the
 *     kernel alive — DAMA's per-test transaction rollback ensures DB
 *     isolation without needing to rebuild the container each time.
 *  4. tearDownAfterClass() restores the original CWD, shuts down the
 *     kernel, cleans up exception handlers, and removes the isolated
 *     directory tree.
 *
 * This per-class strategy is safe because ParaTest runs a whole test
 * class inside a single worker/process, and DAMA provides per-method
 * transaction isolation independently of the kernel lifecycle.
 *
 * CAUTION: Subclasses that mutate the live container via
 * {@see \Symfony\Component\DependencyInjection\Container::set()}
 * must re-set the service in {@see setUp()} each time — the container
 * is shared across test methods within the class.
 *
 * The test database path does NOT depend on the isolated cwd.
 * config/packages/test/doctrine.yaml overrides the DBAL path to a
 * fixed project-relative location, so DAMA can maintain a static
 * connection for transaction rollback between test methods.
 */
abstract class IsolatedKernelTestCase extends KernelTestCase
{
    /** Class-scoped isolated CWD created once in setUpBeforeClass. */
    private static string $classCwd;

    /** @var string|false Original CWD captured before chdir in setUpBeforeClass. */
    private static string|false $originalCwd = false;

    // ── Per-class lifecycle ───────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create ONE isolated cwd for the entire test class.
        self::$classCwd = TestDirectoryIsolation::createProjectTempDir('hatfield-test', 0o750);
        TestDirectoryIsolation::createHatfieldTree(self::$classCwd);

        // Save original cwd so we can restore it in tearDownAfterClass.
        self::$originalCwd = getcwd();

        // chdir into isolated cwd BEFORE kernel boot.
        // Kernel::boot() reads getcwd() into HATFIELD_CWD env var.
        chdir(self::$classCwd);

        // Required env vars for container compilation.
        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['APP_SECRET'] = 'test-secret';
        $_ENV['HATFIELD_CWD'] = self::$classCwd;
        putenv('HATFIELD_CWD='.self::$classCwd);

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

    // ── Per-method lifecycle ──────────────────────────────────────

    protected function setUp(): void
    {
        // Do NOT call parent::setUp() — there is no PHPUnit setup to chain
        // (TestCase::setUp() is an empty hook), and KernelTestCase::bootKernel
        // is handled once per class in setUpBeforeClass.

        // Ensure cwd is still the isolated dir (a prior test may have changed it).
        if (getcwd() !== self::$classCwd) {
            chdir(self::$classCwd);
        }

        // Ensure env vars are still correct (compiled container bakes
        // some at compile time; in-process mutations of $_ENV/putenv
        // by a prior test must be reverted for the next test).
        $_ENV['APP_ENV'] = 'test';
        $_ENV['HATFIELD_CWD'] = self::$classCwd;
        putenv('HATFIELD_CWD='.self::$classCwd);
    }

    protected function tearDown(): void
    {
        // Clear EntityManager identity map so entities persisted in one
        // test method do not appear in another test method's query results
        // (DAMA rolls back the transaction, but the identity map may still
        // hold stale managed entities until explicitly cleared).
        //
        // Do NOT close the EM — closing would invalidate the connection and
        // require re-booting the kernel for the next test.
        if (self::$booted && self::getContainer()->has('doctrine.orm.default_entity_manager')) {
            try {
                /** @var EntityManagerInterface $em */
                $em = self::getContainer()->get('doctrine.orm.default_entity_manager');
                $em->clear();
            } catch (\Throwable) {
                // EM may already be closed; ignore cleanup errors.
            }
        }

        // Do NOT call parent::tearDown() — KernelTestCase::tearDown() calls
        // ensureKernelShutdown() which would destroy the kernel we want to
        // reuse across test methods in this class.
    }

    // ── Class-level cleanup ───────────────────────────────────────

    public static function tearDownAfterClass(): void
    {
        // Close EntityManager (EM stays open across tests with clear() only,
        // so close it now that the class is done).
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
        if (false !== self::$originalCwd) {
            @chdir(self::$originalCwd);
        }

        if (self::$booted) {
            // Manually shut down the kernel INSTEAD of calling
            // ensureKernelShutdown(), which re-boots the kernel
            // (pushing another exception handler) before shutdown.
            // The setUpBeforeClass boot already pushed one handler;
            // we pop it with restore_exception_handler below.
            //
            // parent::tearDownAfterClass() is also skipped — it calls
            // ensureKernelShutdown() as well, which would re-boot,
            // push a third handler, and confuse PHPUnit's exception
            // handler tracking for subsequent test classes.
            self::$kernel->shutdown();
            self::$booted = false;
        }

        // Clean up the isolated directory tree.
        if (isset(self::$classCwd) && is_dir(self::$classCwd)) {
            TestDirectoryIsolation::removeDirectory(self::$classCwd);
        }

        // Pop the exception handler that FrameworkBundle::boot()
        // registered during setUpBeforeClass kernel boot.  This
        // leaves the handler stack exactly as it was before the
        // test class ran, so subsequent KernelTestCase-direct tests
        // (e.g. ModelSelectionServiceTest) do not detect a stray
        // handler as "risky".
        restore_exception_handler();

        // Do NOT call parent::tearDownAfterClass() — it calls
        // ensureKernelShutdown() which re-boots the already-shutdown
        // kernel, pushing yet another handler on the stack.
    }

    // ─── Utility ─────────────────────────────────────────────────────

    /**
     * Returns the isolated project temp directory created during setUpBeforeClass().
     * Subclasses writing temp files (e.g. prompt templates) can use this
     * instead of reflection to find the isolated CWD.
     */
    protected function isolatedCwd(): string
    {
        return self::$classCwd;
    }
}
