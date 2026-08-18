<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: phar_in_use_pids() detects current-user processes whose command
 * line references the artifact path, and phar_skip_rebuild_when_in_use()
 * skips (or fails loudly when the artifact is missing) so a live session
 * executing from the PHAR is never rebuilt under.
 */
final class PharInUseGuardTest extends TestCase
{
    public function testInUseDetectionAndSkipBehavior(): void
    {
        self::requireHelpers();

        $dir = TestDirectoryIsolation::createOsTempDir('phar-in-use-guard');
        $pharPath = $dir.'/standin.phar';
        file_put_contents($pharPath, 'fake phar payload');

        $children = [];
        try {
            // (a) Detection: a spawned child whose argv references the path.
            $children[] = self::spawnChildReferencing($pharPath);
            $this->assertTrue(
                self::pollUntil(static fn (): bool => [] !== \CastorTasks\phar_in_use_pids($pharPath)),
                'phar_in_use_pids() must detect the spawned child',
            );
            $pids = \CastorTasks\phar_in_use_pids($pharPath);
            $this->assertNotEmpty($pids);
            $this->assertNotContains((int) getmypid(), array_keys($pids), 'own pid must never be reported');

            // (b) Skip: artifact exists and is in use → true (with operator message).
            $this->assertTrue(\CastorTasks\phar_skip_rebuild_when_in_use($pharPath));

            // Let the child exit by itself, then the same call must be false.
            $this->assertTrue(
                self::pollUntil(static fn (): bool => [] === \CastorTasks\phar_in_use_pids($pharPath), 6.0),
                'child must exit on its own',
            );
            $this->assertFalse(\CastorTasks\phar_skip_rebuild_when_in_use($pharPath));

            // (c) Missing artifact + in use → hard failure, never a silent build.
            $missingPath = $dir.'/missing.phar';
            $children[] = self::spawnChildReferencing($missingPath);
            $this->assertTrue(
                self::pollUntil(static fn (): bool => [] !== \CastorTasks\phar_in_use_pids($missingPath)),
                'phar_in_use_pids() must detect the child referencing the missing path',
            );
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/in use by live process/');
            \CastorTasks\phar_skip_rebuild_when_in_use($missingPath);
        } finally {
            foreach ($children as $child) {
                if (\is_resource($child)) {
                    proc_terminate($child);
                    proc_close($child);
                }
            }
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    private static function requireHelpers(): void
    {
        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        self::assertFileExists($helpersPhp);
        require_once $helpersPhp;
        self::assertTrue(
            \function_exists('CastorTasks\phar_in_use_pids'),
            'phar_in_use_pids must load from .castor/helpers.php',
        );
        self::assertTrue(
            \function_exists('CastorTasks\phar_skip_rebuild_when_in_use'),
            'phar_skip_rebuild_when_in_use must load from .castor/helpers.php',
        );
    }

    /**
     * Spawn a current-user child that sleeps ~3s; the last argv element is
     * $argvPath, so the process cmdline references it (this is how a live
     * session executing `php .../hatfield.phar` is detected).
     *
     * @return resource
     */
    private static function spawnChildReferencing(string $argvPath)
    {
        return proc_open(
            [\PHP_BINARY, '-r', 'usleep(3000000);', $argvPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
    }

    private static function pollUntil(callable $predicate, float $timeoutSeconds = 3.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($predicate()) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return $predicate();
    }
}
