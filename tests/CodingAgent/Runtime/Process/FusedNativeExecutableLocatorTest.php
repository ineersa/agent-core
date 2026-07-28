<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\ConfigExecutableLocator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Contract: fused native binaries relaunch as a single argv element when the
 * resolved artifact is the current PHP_BINARY; ordinary PHAR/scripts stay
 * [PHP_BINARY, path].
 */
final class FusedNativeExecutableLocatorTest extends TestCase
{
    public function testConfigLocatorReturnsPhpPlusScriptForOrdinaryBinary(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('fused-locator');
        try {
            $script = $dir.'/hatfield.phar';
            file_put_contents($script, '<?php // fake phar script');
            putenv('HATFIELD_BINARY_PATH='.$script);
            try {
                $locator = new ConfigExecutableLocator($dir);
                $cmd = $locator->command();
                $this->assertSame([\PHP_BINARY, $script], $cmd);
            } finally {
                putenv('HATFIELD_BINARY_PATH');
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testConfigLocatorReturnsSingleElementWhenArtifactIsPhpBinary(): void
    {
        // Simulate fused native: HATFIELD_BINARY_PATH points at PHP_BINARY itself.
        $php = \PHP_BINARY;
        if ('' === $php || !is_file($php)) {
            $this->markTestSkipped('PHP_BINARY not a real file on this host');
        }

        putenv('HATFIELD_BINARY_PATH='.$php);
        try {
            $locator = new ConfigExecutableLocator();
            $cmd = $locator->command();
            $this->assertCount(1, $cmd);
            $this->assertSame(realpath($php) ?: $php, realpath($cmd[0]) ?: $cmd[0]);
        } finally {
            putenv('HATFIELD_BINARY_PATH');
        }
    }
}
