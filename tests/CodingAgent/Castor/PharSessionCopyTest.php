<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Session copies remain immutable across rebuilds and overlapping launches.
 */
final class PharSessionCopyTest extends TestCase
{
    public function testMaterializeReusesContentAddressedPathWithoutRewrite(): void
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

            $this->assertSame($sessionsDir.'/'.hash('sha256', $payload).'/hatfield.phar', $copy);
            $this->assertSame($copy, $copyAgain, 'same build must reuse the immutable session copy path');
            $this->assertFileExists($copy);
            $this->assertSame(
                hash_file('sha256', $artifact),
                hash_file('sha256', $copy),
                'session copy must be byte-identical to the canonical artifact',
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testDifferentBuildPreservesPreviousSessionArtifact(): void
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

            $this->assertNotSame($first, $second, 'new build must not replace a live session artifact');
            $this->assertSame('payload v1', file_get_contents($first));
            $this->assertSame('payload v2', file_get_contents($second));
            $this->assertSame('payload v2', file_get_contents($artifact), 'source artifact must remain untouched');
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testCorruptDestFailsWithoutReplacingLiveArtifact(): void
    {
        self::requireHelpers();

        $work = TestDirectoryIsolation::createOsTempDir('phar-session-copy');
        try {
            $artifact = $work.'/canonical.phar';
            $payload = 'fake phar payload '.bin2hex(random_bytes(16));
            file_put_contents($artifact, $payload);

            $sessionsDir = $work.'/sessions';
            $copy = \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);

            file_put_contents($copy, 'corrupted');
            try {
                \CastorTasks\phar_materialize_session_copy($artifact, $sessionsDir);
                $this->fail('Expected corrupt session copy to be rejected');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Corrupt immutable session PHAR', $exception->getMessage());
            }
            $this->assertSame('corrupted', file_get_contents($copy));
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
