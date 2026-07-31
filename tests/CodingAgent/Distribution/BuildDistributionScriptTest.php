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
        // build already writes SHA256SUMS — wrapper must not re-invoke checksums.
        $this->assertStringNotContainsString('distribution:checksums', $logBody);
        $this->assertStringContainsString('distribution:verify', $logBody);
        $this->assertStringContainsString('--skip-topology', $logBody);
        $this->assertStringContainsString('--allow-missing-native', $logBody);
        $this->assertStringNotContainsString('distribution:build-static', $logBody);
        // Exact sequence: build then verify (no intervening checksums task).
        $this->assertMatchesRegularExpression(
            '/ARGS: distribution:build\b.*\nARGS: distribution:verify\b/s',
            $logBody,
        );
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

        // Fail closed if a real lock already exists — never mutate foreign metadata.
        if (is_dir($lockDir) || is_file($ownerFile)) {
            $holder = is_file($ownerFile) ? trim((string) file_get_contents($ownerFile)) : 'unknown';
            $this->fail(
                "distribution-build.lock already present (holder={$holder}); ".
                'refusing to mutate real lock metadata. Remove only an orphan lock you own, then re-run.',
            );
        }

        $work = TestDirectoryIsolation::createProjectTempDir('dist-script-lock');
        $binDir = $work.'/bin';
        mkdir($binDir, 0755, true);
        $holdingGate = $work.'/holding';
        $releaseGate = $work.'/release';
        $fakeCastor = $binDir.'/castor';
        // Hold the distribution lock until the test writes $releaseGate.
        // No wall-clock sleep is the contract — only concurrent lock contention is.
        $holdingGateQ = escapeshellarg($holdingGate);
        $releaseGateQ = escapeshellarg($releaseGate);
        file_put_contents($fakeCastor, <<<BASH
#!/usr/bin/env bash
set -euo pipefail
trap 'exit 0' TERM INT
echo holding >{$holdingGateQ}
deadline=\$((SECONDS + 10))
while [[ ! -f {$releaseGateQ} && \$SECONDS -lt \$deadline ]]; do
  sleep 0.05
done
exit 0
BASH);
        chmod($fakeCastor, 0755);

        $first = null;
        try {
            $first = new Process(
                ['bash', $root.'/scripts/build-distribution.sh', '--phar-only'],
                $root,
                [
                    'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'HATFIELD_DIST_DIR' => $work.'/dist-first',
                ],
            );
            $first->setTimeout(12);
            $first->start();

            // Bounded poll until first process marks ownership and enters fake castor hold.
            $deadline = microtime(true) + 5.0;
            $ownerSeen = false;
            $holdingSeen = false;
            while (microtime(true) < $deadline) {
                if (!$ownerSeen && is_file($ownerFile)) {
                    $owner = trim((string) file_get_contents($ownerFile));
                    if ('' !== $owner && ctype_digit($owner)) {
                        $ownerSeen = true;
                    }
                }
                if (is_file($holdingGate)) {
                    $holdingSeen = true;
                }
                if ($ownerSeen && $holdingSeen) {
                    break;
                }
                if (!$first->isRunning()) {
                    break;
                }
                usleep(20_000);
            }
            $this->assertTrue(
                $ownerSeen && $holdingSeen,
                'First build-distribution.sh did not hold the lock within 5s. '.
                'stdout='.$first->getOutput().' stderr='.$first->getErrorOutput(),
            );

            $second = new Process(
                ['bash', $root.'/scripts/build-distribution.sh', '--phar-only'],
                $root,
                [
                    'PATH' => $binDir.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'HATFIELD_DIST_DIR' => $work.'/dist-second',
                ],
            );
            $second->setTimeout(5);
            $second->run();
            $this->assertFalse($second->isSuccessful(), 'Contending build must fail closed');
            $combined = $second->getOutput().$second->getErrorOutput();
            $this->assertStringContainsString('distribution build lock is held', $combined);
            $this->assertStringContainsString($lockDir, $combined);

            // Release the holder via exact file gate (not a wall-clock sleep).
            file_put_contents($releaseGate, '1');
            $waitDeadline = microtime(true) + 5.0;
            while ($first->isRunning() && microtime(true) < $waitDeadline) {
                usleep(20_000);
            }
            $this->assertFalse($first->isRunning(), 'Holder did not exit after release gate');
        } finally {
            @file_put_contents($releaseGate, '1');
            if (null !== $first && $first->isRunning()) {
                $first->stop(1.0, \SIGTERM);
            }
            // Remove only resources this test created (first holder should release on exit).
            $deadline = microtime(true) + 3.0;
            while ((is_dir($lockDir) || is_file($ownerFile)) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            if (is_file($ownerFile)) {
                $holder = trim((string) file_get_contents($ownerFile));
                // Only clear if the first process we started still owns it.
                if (null !== $first && (string) $first->getPid() === $holder) {
                    @unlink($ownerFile);
                    @rmdir($lockDir);
                }
            } elseif (is_dir($lockDir)) {
                @rmdir($lockDir);
            }
            TestDirectoryIsolation::removeDirectory($work);
        }
    }
}
