<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv
 */
final class TuiE2eDatabaseEnvTest extends TestCase
{
    public function testShellPrefixSetsPairedDatabaseEnvVars(): void
    {
        $prefix = TuiE2eDatabaseEnv::shellPrefix(
            'app_test-abc.sqlite',
            'messenger_transport_test-abc.sqlite',
        );

        $this->assertStringContainsString('HATFIELD_TEST_DATABASE_PATH=', $prefix);
        $this->assertStringContainsString('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=', $prefix);
        $this->assertStringContainsString('app_test-abc.sqlite', $prefix);
        $this->assertStringContainsString('messenger_transport_test-abc.sqlite', $prefix);
    }

    public function testAllocatePathsFromAppBasenamePairsTransportFilename(): void
    {
        $paths = TuiE2eDatabaseEnv::allocatePathsFromAppBasename('app_test-tui-journey-deadbeef.sqlite');

        $this->assertSame('app_test-tui-journey-deadbeef.sqlite', $paths['app']);
        $this->assertSame('messenger_transport_test-tui-journey-deadbeef.sqlite', $paths['transport']);
    }

    public function testDoctrineEnvPathResolvesToIsolatedAbsolutePath(): void
    {
        $kernelRoot = '/worktree';
        $isolated = '/worktree/var/tmp/tui-e2e-repair-deadbeef';
        $basename = 'app_test-tui-repair-cafe.sqlite';

        $absolute = TuiE2eDatabaseEnv::isolatedSqliteAbsolutePath($isolated, $basename);
        $envPath = TuiE2eDatabaseEnv::doctrineEnvPathForIsolatedSqlite($kernelRoot, $isolated, $basename);

        $this->assertSame($isolated.'/.hatfield/tmp/test-db/'.$basename, $absolute);
        $this->assertSame(
            '../tmp/tui-e2e-repair-deadbeef/.hatfield/tmp/test-db/'.$basename,
            $envPath,
        );
        $doctrineResolved = $kernelRoot.'/var/test/'.$envPath;
        $this->assertStringEndsWith('/.hatfield/tmp/test-db/'.$basename, $doctrineResolved);
        $this->assertStringContainsString('/var/test/../tmp/', $doctrineResolved);
    }
}
