<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\TestCase;

use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base class for tests that need a FRESH kernel boot and isolated CWD
 * for EVERY test method.
 *
 * Use this only when per-class kernel boot ({@see IsolatedKernelTestCase})
 * is insufficient — for example when tests mutate the live container via
 * {@see \Symfony\Component\DependencyInjection\Container::set()}, when
 * services cache state that cannot be cleared between methods, or when
 * per-method filesystem artifacts must be visible to a freshly-booted kernel.
 *
 * For most DB/config tests, the per-class {@see IsolatedKernelTestCase} is
 * preferred because it is dramatically faster.
 *
 * Lifecycle per test method:
 *  1. setUp(): creates isolated CWD + .hatfield tree, sets env vars,
 *     boots the kernel, then calls {@see afterKernelBoot()} so subclasses
 *     can install spies/mocks.
 *  2. tearDown(): restores original CWD, shuts down kernel directly
 *     (avoiding ensureKernelShutdown's re-boot side effect), removes
 *     the isolated directory, and pops the exception handler.
 *
 * Subclasses override {@see afterKernelBoot()} — not setUp() or tearDown() —
 * to inject spies or perform one-time per-method setup after kernel boot.
 */
abstract class PerMethodIsolatedKernelTestCase extends KernelTestCase
{
    private string $isolatedCwd;
    private string|false $originalCwd;

    // ── Per-method lifecycle ─────────────────────────────────────

    protected function setUp(): void
    {
        // Do NOT call parent::setUp() — KernelTestCase::setUp() is an
        // empty hook, and kernel boot is handled here.

        $this->isolatedCwd = TestDirectoryIsolation::createProjectTempDir('hatfield-test', 0o750);
        TestDirectoryIsolation::createHatfieldTree($this->isolatedCwd);

        $this->originalCwd = getcwd();
        chdir($this->isolatedCwd);

        $_ENV['APP_ENV'] = 'test';
        $_ENV['APP_DEBUG'] = '0';
        $_ENV['APP_SECRET'] = 'test-secret';
        $_ENV['HATFIELD_CWD'] = $this->isolatedCwd;
        putenv('HATFIELD_CWD='.$this->isolatedCwd);

        // Boot without debug mode so Symfony ErrorHandler does not leave
        // exception handlers on PHPUnit's stack and mark tests risky.
        self::bootKernel(['environment' => 'test', 'debug' => false]);

        // Hook for subclasses to install spies/mocks after kernel boot.
        $this->afterKernelBoot();
    }

    protected function tearDown(): void
    {
        // Restore original CWD before kernel shutdown.
        if (false !== $this->originalCwd) {
            @chdir($this->originalCwd);
        }

        if (self::$booted) {
            // Direct shutdown to avoid ensureKernelShutdown's internal
            // re-boot, which would push an extra exception handler and
            // leak it onto the handler stack for downstream test classes.
            self::$kernel->shutdown();
            self::$booted = false;
        }

        // Clean up the isolated directory tree.
        if (isset($this->isolatedCwd) && is_dir($this->isolatedCwd)) {
            TestDirectoryIsolation::removeDirectory($this->isolatedCwd);
        }

        // Pop the exception handler that FrameworkBundle::boot()
        // pushed during kernel boot.  This balances the handler stack
        // exactly: one boot → one restore.
        restore_exception_handler();

        // Do NOT call parent::tearDown() — KernelTestCase::tearDown()
        // calls ensureKernelShutdown() which re-boots the kernel.
    }

    /**
     * Override in subclasses to install spies, configure container
     * overrides via Container::set(), or perform other per-method
     * setup that requires a booted kernel.
     *
     * Called from setUp() immediately after kernel boot, before the
     * test method body runs.
     */
    protected function afterKernelBoot(): void
    {
        // Default: no-op.
    }

    // ── Kernel factory ───────────────────────────────────────────

    /**
     * Override so subclasses don't need KERNEL_CLASS in phpunit.xml.dist.
     */
    protected static function createKernel(array $options = []): Kernel
    {
        $env = $options['environment'] ?? $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'test';
        $debug = (bool) ($options['debug'] ?? $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? false);

        return new Kernel($env, $debug);
    }

    // ─── Utility ─────────────────────────────────────────────────

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
