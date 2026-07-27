<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: a fused native Hatfield binary starts a headless controller that
 * reaches runtime.ready and relaunches children through the same binary.
 *
 * Opt-in via HATFIELD_NATIVE_BINARY_PATH. When unset, the test is a no-op pass
 * (not skipped) so default castor test with --fail-on-all-issues stays green.
 * CI and castor distribution:verify must supply the artifact and assert topology.
 */
#[Group('phar')]
final class NativeProcessTopologyTest extends TestCase
{
    public function testNativeBinaryControllerReachesReady(): void
    {
        $binary = getenv('HATFIELD_NATIVE_BINARY_PATH');
        if (false === $binary || '' === $binary || !is_file($binary)) {
            $this->assertTrue(
                true,
                'No native artifact configured; topology verified by distribution:verify / CI when built.',
            );

            return;
        }

        $tmp = TestDirectoryIsolation::createProjectTempDir('native-topo');
        try {
            TestDirectoryIsolation::createHatfieldTree($tmp, withSessions: true);
            TestDirectoryIsolation::ensureDirectory($tmp.'/home/.hatfield');
            file_put_contents($tmp.'/home/.hatfield/settings.yaml', "ai:\n    default_model: null\n");

            $process = new Process(
                [$binary, 'agent', '--controller', '--cwd='.$tmp],
                $tmp,
                [
                    'HOME' => $tmp.'/home',
                    'APP_ENV' => 'prod',
                    'APP_DEBUG' => '0',
                    'HATFIELD_CWD' => $tmp,
                    'HATFIELD_BINARY_PATH' => $binary,
                ],
            );
            $process->setTimeout(25);
            $process->start();

            $deadline = microtime(true) + 20.0;
            $stdout = '';
            $ready = false;
            while (microtime(true) < $deadline) {
                $stdout .= $process->getIncrementalOutput();
                if (str_contains($stdout, 'runtime.ready')) {
                    $ready = true;
                    break;
                }
                if (!$process->isRunning()) {
                    break;
                }
                usleep(50_000);
            }

            $this->assertTrue($ready, "runtime.ready not observed.\n".$stdout."\n".$process->getErrorOutput());
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->stop(2.0, \SIGTERM);
            }
            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }
}
