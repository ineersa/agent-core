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

        self::assertStringContainsString('HATFIELD_TEST_DATABASE_PATH=', $prefix);
        self::assertStringContainsString('HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=', $prefix);
        self::assertStringContainsString('app_test-abc.sqlite', $prefix);
        self::assertStringContainsString('messenger_transport_test-abc.sqlite', $prefix);
    }

    public function testAllocatePathsFromAppBasenamePairsTransportFilename(): void
    {
        $paths = TuiE2eDatabaseEnv::allocatePathsFromAppBasename('app_test-tui-journey-deadbeef.sqlite');

        self::assertSame('app_test-tui-journey-deadbeef.sqlite', $paths['app']);
        self::assertSame('messenger_transport_test-tui-journey-deadbeef.sqlite', $paths['transport']);
    }
}
