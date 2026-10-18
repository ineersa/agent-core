<?php

declare(strict_types=1);

namespace {
    require_once __DIR__.'/Support/MicroSelfFilenamePolyfill.php';
}

namespace Ineersa\CodingAgent\Tests\Runtime\Process {
    use Ineersa\CodingAgent\Runtime\Process\ConfigExecutableLocator;
    use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\TestCase;
    use Symfony\Component\Process\Process;

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

        #[Group('native-artifact')]
        public function testOtherNativeOverrideWithEmptyPhpBinary(): void
        {
            $root = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
            require_once $root.'/.castor/helpers.php';
            require_once $root.'/.castor/distribution.php';
            $spc = $root.'/var/tmp/static-php-cli/'.distribution_static_pin()['static_php_cli_commit'].'/bin/spc';
            $micro = $root.'/var/tmp/static-build/'.distribution_host_target().'/buildroot/bin/micro.sfx';
            if (!is_file($spc) || !is_file($micro)) {
                $this->markTestSkipped('Pinned micro engine and SPC checkout required for native override proof.');
            }
            $dir = TestDirectoryIsolation::createProjectTempDir('native-override');
            try {
                $script = $dir.'/locator.php';
                file_put_contents($script, '<?php require '.var_export($root.'/src/CodingAgent/Runtime/Process/AppExecutableLocator.php', true).'; require '.var_export($root.'/src/CodingAgent/Runtime/Process/ConfigExecutableLocator.php', true).'; echo json_encode([PHP_BINARY, (new \\Ineersa\\CodingAgent\\Runtime\\Process\\ConfigExecutableLocator())->command()]);');
                $native = $dir.'/self';
                distribution_combine_micro($spc, $micro, $script, $native, $dir);
                copy($native, $dir.'/other');
                chmod($dir.'/other', 0755);
                link($native, $dir.'/hardlink');
                foreach (['other', 'hardlink'] as $name) {
                    $override = $dir.'/'.$name;
                    $process = new Process([$native], $dir, ['HATFIELD_BINARY_PATH' => $override]);
                    $process->mustRun();
                    $this->assertSame(['', [$override]], json_decode($process->getOutput(), true, 16, \JSON_THROW_ON_ERROR));
                }
            } finally {
                TestDirectoryIsolation::removeDirectory($dir);
            }
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

                // Simulate fused packaging: PHP_BINARY may differ from the resolved
                // fused self-executable while micro_get_self_filename() reports self.
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
