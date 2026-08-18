<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: phar_materialize_session_copy() creates a byte-identical,
 * atomic session-owned copy of the canonical artifact under
 * var/tmp/phar/sessions/, and phar_gc_session_copies() removes only stale
 * copies (old mtime) while preserving fresh copies, the just-created copy,
 * and copies a live process is executing from.
 */
final class PharSessionCopyTest extends TestCase
{
    public function testMaterializeCreatesByteIdenticalAtomicCopy(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-copy');
        try {
            $artifact = $work.'/canonical.phar';
            $payload = 'fake phar payload '.bin2hex(random_bytes(16));
            file_put_contents($artifact, $payload);

            $sessionsDir = $work.'/sessions';
            $copy = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            $this->assertStringStartsWith($sessionsDir.'/', $copy);
            $this->assertFileExists($copy);
            $this->assertSame(
                hash_file('sha256', $artifact),
                hash_file('sha256', $copy),
                'session copy must be byte-identical to the canonical artifact',
            );

            // Atomic: no leftover temp file (including dotfiles) in the copy dir.
            $entries = [];
            foreach (new \FilesystemIterator(\dirname($copy)) as $entry) {
                $entries[] = $entry->getFilename();
            }
            sort($entries);
            $this->assertSame(['hatfield.phar'], $entries);
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testGcRemovesOnlyStaleCopies(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-gc');
        try {
            $artifact = $work.'/canonical.phar';
            file_put_contents($artifact, 'payload');

            $sessionsDir = $work.'/sessions';
            $fresh = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            // A stale copy (dir mtime beyond the 10 s cutoff) must be removed.
            $stale = $sessionsDir.'/stale-session';
            mkdir($stale, 0755, true);
            file_put_contents($stale.'/hatfield.phar', 'stale');
            touch($stale, time() - 60);

            // A young copy (mtime within cutoff) must be preserved.
            $young = $sessionsDir.'/young-session';
            mkdir($young, 0755, true);
            file_put_contents($young.'/hatfield.phar', 'young');
            touch($young, time() - 5);

            // A stale copy a live process is executing from must be preserved.
            $staleInUse = $sessionsDir.'/stale-in-use-session';
            mkdir($staleInUse, 0755, true);
            file_put_contents($staleInUse.'/hatfield.phar', 'live');
            touch($staleInUse, time() - 60);
            $child = self::spawnChildReferencing($staleInUse.'/hatfield.phar');

            $this->assertTrue(
                self::pollUntil(static fn (): bool => [] !== \CastorTasks\phar_in_use_pids($staleInUse.'/hatfield.phar')),
                'precondition: child must be detected executing from the stale-in-use copy',
            );

            try {
                \CastorTasks\phar_gc_session_copies(10, $sessionsDir);

                $this->assertDirectoryDoesNotExist($stale);
                $this->assertDirectoryExists($young);
                $this->assertDirectoryExists(\dirname($fresh), 'just-created copy must survive GC');
                $this->assertFileExists($fresh);
                $this->assertDirectoryExists($staleInUse, 'in-use copy must survive GC');
            } finally {
                proc_terminate($child);
                proc_close($child);
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    private static function requireHelpers(): void
    {
        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        self::assertFileExists($helpersPhp);
        require_once $helpersPhp;
        self::assertTrue(
            \function_exists('CastorTasks\phar_materialize_session_copy'),
            'phar_materialize_session_copy must load from .castor/helpers.php',
        );
        self::assertTrue(
            \function_exists('CastorTasks\phar_gc_session_copies'),
            'phar_gc_session_copies must load from .castor/helpers.php',
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
