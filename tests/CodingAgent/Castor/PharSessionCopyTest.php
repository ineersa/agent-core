<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: phar_materialize_session_copy() creates byte-identical,
 * content-addressed session copies of the canonical artifact under
 * var/tmp/phar/sessions/<sha256-16>/, reusing one immutable copy per
 * distinct build (never rewriting a path a live process execs). Copies
 * accumulate per build and are swept by `castor clean:cleanup`.
 */
final class PharSessionCopyTest extends TestCase
{
    public function testMaterializeReusesSingleContentAddressedCopy(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-copy');
        try {
            $artifact = $work.'/canonical.phar';
            $payload = 'fake phar payload '.bin2hex(random_bytes(16));
            file_put_contents($artifact, $payload);

            $sessionsDir = $work.'/sessions';
            $copy = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);
            $copyAgain = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            // Same build -> same content-addressed path; second call reuses it.
            $this->assertSame($copy, $copyAgain, 'same build must reuse the same session copy path');

            $hashPrefix = substr((string) hash_file('sha256', $artifact), 0, 16);
            $this->assertSame($sessionsDir.'/'.$hashPrefix.'/hatfield.phar', $copy);
            $this->assertFileExists($copy);
            $this->assertSame(
                hash_file('sha256', $artifact),
                hash_file('sha256', $copy),
                'session copy must be byte-identical to the canonical artifact',
            );

            // Exactly one content-addressed dir, containing exactly the artifact.
            $dirs = [];
            foreach (new \FilesystemIterator($sessionsDir) as $entry) {
                $dirs[] = $entry->getFilename();
            }
            $this->assertSame([$hashPrefix], $dirs);

            $inner = [];
            foreach (new \FilesystemIterator(\dirname($copy)) as $entry) {
                $inner[] = $entry->getFilename();
            }
            sort($inner);
            $this->assertSame(['hatfield.phar'], $inner, 'atomic copy: no temp leftovers');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testDifferentBuildMaterializesDifferentCopyDir(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-copy');
        try {
            $artifact = $work.'/canonical.phar';
            $sessionsDir = $work.'/sessions';

            file_put_contents($artifact, 'payload v1');
            $first = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            file_put_contents($artifact, 'payload v2');
            $second = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            // Different build -> different content-addressed dir; both kept.
            $this->assertNotSame($first, $second);
            $this->assertNotSame(\dirname($first), \dirname($second));
            $this->assertFileExists($first);
            $this->assertFileExists($second);
            $this->assertSame('payload v2', file_get_contents($second));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testCorruptDestIsRecopied(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-copy');
        try {
            $artifact = $work.'/canonical.phar';
            $payload = 'fake phar payload '.bin2hex(random_bytes(16));
            file_put_contents($artifact, $payload);

            $sessionsDir = $work.'/sessions';
            $copy = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            // Corrupt the dest: next materialize must detect the hash mismatch,
            // re-copy atomically to the same path, and restore correct bytes.
            file_put_contents($copy, 'corrupted');
            $repaired = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            $this->assertSame($copy, $repaired);
            $this->assertSame(
                hash_file('sha256', $artifact),
                hash_file('sha256', $repaired),
                'corrupt dest must be re-copied from the canonical artifact',
            );
            $this->assertSame($payload, file_get_contents($repaired));
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
    }
}
