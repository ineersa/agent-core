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
}
