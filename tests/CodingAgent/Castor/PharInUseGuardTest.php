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
        $this->assertSame(
            realpath($pharPath),
            $pharPath,
            'spawned child argv must carry the absolute resolved path for token-exact matching',
        );

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

    /**
     * Regression: the root-relative needle form (var/tmp/phar/hatfield.phar)
     * is a SUBSTRING of every sibling worktree's absolute artifact path. With
     * token-exact matching the sibling path must never be reported as in-use
     * for this worktree.
     */
    public function testSiblingWorktreeArtifactPathIsNotMatched(): void
    {
        self::requireHelpers();

        $root = ProjectDir::get();
        $thisPhar = $root.'/var/tmp/phar/hatfield.phar';
        $needles = array_values(array_unique(array_filter([
            realpath($thisPhar) ?: $thisPhar,
            $thisPhar,
        ])));

        // A live session in a sibling worktree execs ITS absolute artifact
        // path, which contains this worktree's root-relative form only as a
        // substring — never as an equal argv token.
        $siblingSession = "/usr/bin/php\0/home/ineersa/projects/agent-core-worktrees/some-other-task/var/tmp/phar/hatfield.phar\0agent\0--controller";
        $this->assertFalse(\CastorTasks\phar_cmdline_uses_artifact($siblingSession, $needles));

        // A token that merely embeds the path (shell snippet, grep pattern,
        // editor buffer) must not match either.
        $embedded = "/bin/sh\0-c\0echo see {$thisPhar} in a script\0";
        $this->assertFalse(\CastorTasks\phar_cmdline_uses_artifact($embedded, $needles));

        // Positive control: an argv token exactly equal to the artifact path.
        $ownSession = "/usr/bin/php\0".$thisPhar."\0agent\0--controller";
        $this->assertTrue(\CastorTasks\phar_cmdline_uses_artifact($ownSession, $needles));
    }

    /**
     * End-to-end /proc-level regression: a spawned child referencing a
     * sibling-worktree-shaped absolute artifact path must not show up in
     * phar_in_use_pids() for this worktree's artifact.
     */
    public function testSiblingWorktreeSessionDoesNotBlockThisWorktree(): void
    {
        self::requireHelpers();

        $root = ProjectDir::get();
        $thisPhar = $root.'/var/tmp/phar/hatfield.phar';
        $siblingPhar = '/home/ineersa/projects/agent-core-worktrees/some-other-task/var/tmp/phar/hatfield.phar';

        $child = null;
        try {
            $child = self::spawnChildReferencing($siblingPhar);
            usleep(300_000); // let /proc settle; the child lives ~3 s
            $status = proc_get_status($child);
            $this->assertTrue($status['running'], 'precondition: sibling-referencing child must be alive while asserting');
            $this->assertSame(
                [],
                \CastorTasks\phar_in_use_pids($thisPhar),
                'a sibling worktree session must not block this worktree rebuild',
            );
        } finally {
            if (\is_resource($child)) {
                proc_terminate($child);
                proc_close($child);
            }
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
        self::assertTrue(
            \function_exists('CastorTasks\phar_cmdline_uses_artifact'),
            'phar_cmdline_uses_artifact must load from .castor/helpers.php',
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
