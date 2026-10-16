<?php

declare(strict_types=1);

namespace {
    require_once __DIR__.'/Support/MicroSelfFilenamePolyfill.php';
}

namespace Ineersa\CodingAgent\Tests\Runtime\Process {
    use Ineersa\CodingAgent\Runtime\Process\ConfigExecutableLocator;
    use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
    use PHPUnit\Framework\TestCase;

    /**
     * Contract: fused native relaunches as a single argv element when the resolved
     * artifact is the running PHP-micro self (or, without that helper, when it is
     * the current PHP_BINARY). Ordinary PHAR/scripts stay [PHP_BINARY, path].
     */
    final class FusedNativeExecutableLocatorTest extends TestCase
    {
        protected function tearDown(): void
        {
            $GLOBALS['hatfield_test_micro_self'] = '';
            putenv('HATFIELD_BINARY_PATH');
            parent::tearDown();
        }

        public function testConfigLocatorReturnsPhpPlusScriptForOrdinaryBinary(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('fused-locator');
            try {
                $script = $dir.'/hatfield.phar';
                file_put_contents($script, '<?php // fake phar script');
                putenv('HATFIELD_BINARY_PATH='.$script);
                $locator = new ConfigExecutableLocator($dir);
                $cmd = $locator->command();
                $this->assertSame([\PHP_BINARY, $script], $cmd);
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }

        public function testConfigLocatorReturnsSingleElementWhenArtifactIsPhpBinary(): void
        {
            // Legacy fused-native signal without a meaningful micro self: path equals PHP_BINARY.
            $php = \PHP_BINARY;
            if ('' === $php || !is_file($php)) {
                $this->markTestSkipped('PHP_BINARY not a real file on this host');
            }

            $GLOBALS['hatfield_test_micro_self'] = '';
            putenv('HATFIELD_BINARY_PATH='.$php);
            $locator = new ConfigExecutableLocator();
            $cmd = $locator->command();
            $this->assertCount(1, $cmd);
            $this->assertSame(realpath($php) ?: $php, realpath($cmd[0]) ?: $cmd[0]);
        }

        public function testConfigLocatorUsesMicroSelfWhenPhpBinaryIsSeparateOwnedCli(): void
        {
            $dir = TestDirectoryIsolation::createProjectTempDir('fused-locator-micro');
            try {
                $fused = $dir.'/hatfield.linux-amd64';
                $plainPhar = $dir.'/hatfield.phar';
                file_put_contents($fused, '<?php // fake fused native');
                file_put_contents($plainPhar, '<?php // plain phar');
                chmod($fused, 0755);

                // Simulate evaluation packaging: PHP_BINARY is an owned static CLI,
                // while the resolved artifact is the fused self-executable.
                $GLOBALS['hatfield_test_micro_self'] = $fused;
                putenv('HATFIELD_BINARY_PATH='.$fused);
                $fusedCmd = (new ConfigExecutableLocator($dir))->command();
                $this->assertSame([$fused], $fusedCmd);

                // A config override that is not the running self stays php+path.
                putenv('HATFIELD_BINARY_PATH='.$plainPhar);
                $plainCmd = (new ConfigExecutableLocator($dir))->command();
                $this->assertSame([\PHP_BINARY, $plainPhar], $plainCmd);
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }
    }
}
