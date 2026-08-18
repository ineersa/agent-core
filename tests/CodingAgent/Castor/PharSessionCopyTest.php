<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: phar_materialize_session_copy() creates one fixed session copy at
 * var/tmp/phar/sessions/hatfield.phar — same build reused untouched, new build
 * overwrites in place (serialized launches). Swept by `castor clean:cleanup`.
 */
final class PharSessionCopyTest extends TestCase
{
    public function testMaterializeReusesFixedPathWithoutRewrite(): void
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

            $this->assertSame($sessionsDir.'/hatfield.phar', $copy);
            $this->assertSame($copy, $copyAgain, 'same build must reuse the fixed session copy path');
            $this->assertFileExists($copy);
            $this->assertSame(
                hash_file('sha256', $artifact),
                hash_file('sha256', $copy),
                'session copy must be byte-identical to the canonical artifact',
            );

            $entries = [];
            foreach (new \FilesystemIterator($sessionsDir) as $entry) {
                $entries[] = $entry->getFilename();
            }
            sort($entries);
            $this->assertSame(['hatfield.phar'], $entries, 'sessions dir holds exactly one fixed copy');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testDifferentBuildOverwritesFixedPathInPlace(): void
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

            $this->assertSame($first, $second, 'new build must overwrite the same fixed path');
            $this->assertSame($sessionsDir.'/hatfield.phar', $second);
            $this->assertSame('payload v2', file_get_contents($second));
            $this->assertSame('payload v2', file_get_contents($artifact), 'source artifact must remain untouched');
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
            // re-copy in place to the same path, and restore correct bytes.
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
