<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: scripts/build-distribution.sh is concurrency-safe, validates options,
 * and PHAR-only mode verifies with --skip-topology --allow-missing-native.
 *
 * Uses a fake `castor` on PATH so no real packaging runs.
 */
final class BuildDistributionScriptTest extends TestCase
{
    public function testPharOnlyInvokesCastorWithAllowMissingNativeAndSpaceReleaseVersion(): void
    {
        $root = ProjectDir::get();
        $work = TestDirectoryIsolation::createProjectTempDir('dist-script');
        $log = $work.'/castor-invocations.log';
        $binDir = $work.'/bin';
        mkdir($binDir, 0755, true);

        $fakeCastor = $binDir.'/castor';
        file_put_contents($fakeCastor, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
LOG="${HATFIELD_FAKE_CASTOR_LOG:?}"
{
  printf 'ARGS:'
  printf ' %q' "$@"
  printf '\n'
} >>"${LOG}"
exit 0
BASH);
        chmod($fakeCastor, 0755);

        $script = $root.'/scripts/build-distribution.sh';
        $this->assertFileExists($script);

        $process = new Process(
            ['bash', $script, '--phar-only', '--release-version', '1.2.3', '--commit', 'abc1234'],
            $root,
            [
                'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                'HATFIELD_FAKE_CASTOR_LOG' => $log,
                'HATFIELD_DIST_DIR' => $work.'/dist',
                // Keep lock under isolated worktree temp by using repo default;
                // script locks REPO_ROOT/var/tmp — serialize via unique env not available.
                // We still assert invocation shape; lock contention covered separately.
            ],
        );
        $process->setTimeout(20);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());
        $this->assertFileExists($log);
        $logBody = (string) file_get_contents($log);
        $this->assertStringContainsString('distribution:build', $logBody);
        $this->assertStringContainsString('--release-version=1.2.3', $logBody);
        $this->assertStringContainsString('--commit=abc1234', $logBody);
        $this->assertStringContainsString('distribution:checksums', $logBody);
        $this->assertStringContainsString('distribution:verify', $logBody);
        $this->assertStringContainsString('--skip-topology', $logBody);
        $this->assertStringContainsString('--allow-missing-native', $logBody);
        $this->assertStringNotContainsString('distribution:build-static', $logBody);
        TestDirectoryIsolation::removeDirectory($work);
    }

    public function testEmptyVersionEqualsFormFailsClosed(): void
    {
        $root = ProjectDir::get();
        $binDir = TestDirectoryIsolation::createProjectTempDir('dist-script-empty');
        $fakeCastor = $binDir.'/castor';
        file_put_contents($fakeCastor, "#!/usr/bin/env bash\necho should-not-run >&2\nexit 99\n");
        chmod($fakeCastor, 0755);

        $process = new Process(
            ['bash', $root.'/scripts/build-distribution.sh', '--version='],
            $root,
            [
                'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            ],
        );
        $process->setTimeout(10);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $combined = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('requires a non-empty value', $combined);
        TestDirectoryIsolation::removeDirectory($binDir);
    }

    public function testLockContentionFailsClosed(): void
    {
        $root = ProjectDir::get();
        $lockDir = $root.'/var/tmp/distribution-build.lock';
        $ownerFile = $lockDir.'/owner';
        $created = false;
        try {
            if (!is_dir($lockDir)) {
                $this->assertTrue(mkdir($lockDir, 0755, true));
                $created = true;
            }
            // Hold lock as a live PID (this PHPUnit process) so script fails closed.
            file_put_contents($ownerFile, (string) getmypid());

            $binDir = TestDirectoryIsolation::createProjectTempDir('dist-script-lock');
            $fakeCastor = $binDir.'/castor';
            file_put_contents($fakeCastor, "#!/usr/bin/env bash\necho should-not-run >&2\nexit 99\n");
            chmod($fakeCastor, 0755);

            $process = new Process(
                ['bash', $root.'/scripts/build-distribution.sh', '--phar-only'],
                $root,
                [
                    'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                ],
            );
            $process->setTimeout(10);
            $process->run();
            $this->assertFalse($process->isSuccessful());
            $combined = $process->getOutput().$process->getErrorOutput();
            $this->assertStringContainsString('distribution build lock is held', $combined);
            $this->assertStringContainsString($lockDir, $combined);
            TestDirectoryIsolation::removeDirectory($binDir);
        } finally {
            if ($created) {
                @unlink($ownerFile);
                @rmdir($lockDir);
            } elseif (is_file($ownerFile) && trim((string) file_get_contents($ownerFile)) === (string) getmypid()) {
                @unlink($ownerFile);
                @rmdir($lockDir);
            }
        }
    }
}
