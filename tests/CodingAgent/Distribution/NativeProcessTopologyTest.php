<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract: a fused native Hatfield binary starts a headless controller that
 * reaches runtime.ready, relaunches Messenger children through the same
 * one-element native executable, and leaves no owned descendants after stop.
 *
 * Opt-in via HATFIELD_NATIVE_BINARY_PATH. When unset/missing, this is a real
 * PHPUnit skip so generic source suites stay honest. CI and
 * castor distribution:verify must supply the artifact and hard-fail without it.
 *
 * No-leak assertion captures owned PIDs WHILE the controller is alive. After
 * exit, orphans reparent so pgrep -P <dead-controller> would false-pass.
 */
#[Group('phar')]
#[Group('native-artifact')]
final class NativeProcessTopologyTest extends TestCase
{
    /** @var list<string> */
    private const array EXPECTED_TRANSPORTS = [
        'run_control',
        'llm',
        'tool',
        'agent',
        'scheduler_default',
        'mcp',
        'extension_agent',
    ];

    public function testNativeBinaryControllerReachesReadyAndRelaunchesConsumers(): void
    {
        $binary = getenv('HATFIELD_NATIVE_BINARY_PATH');
        if (false === $binary || '' === trim((string) $binary) || !is_file($binary)) {
            $this->markTestSkipped(
                'HATFIELD_NATIVE_BINARY_PATH not set to a native artifact. '
                .'CI / castor distribution:verify must supply it; generic suites skip.',
            );
        }

        $binary = realpath($binary) ?: $binary;
        $this->assertTrue(is_executable($binary), 'Native artifact must be executable: '.$binary);

        $tmp = TestDirectoryIsolation::createProjectTempDir('native-topo');
        $process = null;
        /** @var list<array{pid: int, cmdline: string}> $ownedSnapshot */
        $ownedSnapshot = [];
        $controllerPid = 0;
        $controllerCmdline = '';
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
            $process->setTimeout(40);
            $process->start();

            $deadline = microtime(true) + 25.0;
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

            $this->assertTrue(
                $ready,
                "runtime.ready not observed.\n".$stdout."\n".$process->getErrorOutput(),
            );

            $pid = $process->getPid();
            $this->assertNotNull($pid);
            $this->assertGreaterThan(0, $pid);
            $controllerPid = (int) $pid;
            $controllerCmdline = $this->readProcessCmdline($controllerPid);
            if ('' === $controllerCmdline) {
                $controllerCmdline = $binary.' agent --controller';
            }

            // runtime.ready is emitted before ConsumerSupervisor launches — wait for transports.
            $consumeLines = [];
            $descendants = [];
            $transportDeadline = microtime(true) + 15.0;
            $lastDump = '';
            while (microtime(true) < $transportDeadline) {
                $descendants = $this->collectDescendantCmdlines($controllerPid);
                $lastDump = $this->formatDescendants($descendants);
                $consumeLines = [];
                foreach ($descendants as $row) {
                    if (str_contains($row['cmdline'], 'messenger:consume')) {
                        $consumeLines[] = $row['cmdline'];
                    }
                }
                if ([] !== $consumeLines && $this->hasAllTransports($consumeLines)) {
                    break;
                }
                usleep(100_000);
            }

            $this->assertNotEmpty(
                $consumeLines,
                "No messenger:consume descendants after runtime.ready.\nDescendants:\n".$lastDump,
            );
            $joined = implode("\n", $consumeLines);
            foreach (self::EXPECTED_TRANSPORTS as $transport) {
                $this->assertStringContainsString(
                    $transport,
                    $joined,
                    "Expected messenger transport '{$transport}' after runtime.ready.\n".$joined."\nAll:\n".$lastDump,
                );
            }

            $artifactBase = basename($binary);
            foreach ($consumeLines as $line) {
                $usesArtifact = str_contains($line, $binary) || str_contains($line, $artifactBase);
                $usesSource = str_contains($line, 'bin/console');
                $usesSystemPhp = (bool) preg_match('#(?:^|\s)(?:/usr/bin/php|/usr/local/bin/php|php)\s+#', $line)
                    && !str_starts_with(trim($line), $binary)
                    && !str_starts_with(trim($line), $artifactBase);

                $this->assertFalse(
                    $usesSource && !$usesArtifact,
                    'messenger child uses source bin/console: '.$line,
                );
                $this->assertFalse(
                    $usesSystemPhp,
                    'messenger child relaunched via system PHP: '.$line,
                );
                $this->assertTrue(
                    $usesArtifact,
                    'messenger child must reference native artifact path: '.$line,
                );
            }

            // Capture owned PIDs WHILE controller is still alive.
            $ownedSnapshot = [];
            foreach ($descendants as $row) {
                if (
                    str_contains($row['cmdline'], $binary)
                    || str_contains($row['cmdline'], 'messenger:consume')
                    || str_contains($row['cmdline'], $artifactBase)
                ) {
                    $ownedSnapshot[] = $row;
                }
            }
            $this->assertNotEmpty(
                $ownedSnapshot,
                "Owned PID snapshot empty after topology observation.\n".$lastDump,
            );
        } finally {
            if (null !== $process && $process->isRunning()) {
                // Only signal the controller this test created — never descendants.
                $process->stop(5.0, \SIGTERM);
            }

            if ($controllerPid > 0 && [] !== $ownedSnapshot) {
                $this->assertOwnedPidsGone($ownedSnapshot, $controllerPid, $controllerCmdline, 5.0);
            }

            TestDirectoryIsolation::removeDirectory($tmp);
        }
    }

    public function testOwnedPidStillAliveTreatsPidReuseAsGone(): void
    {
        // Focused helper contract: dead pid / reused pid are not leaks.
        $this->assertFalse($this->ownedPidStillAlive(0, 'anything'));
        $this->assertFalse($this->ownedPidStillAlive(999_999_999, 'definitely-not-a-process'));

        $selfPid = getmypid();
        $this->assertNotFalse($selfPid);
        $selfCmd = $this->readProcessCmdline((int) $selfPid);
        $this->assertNotSame('', $selfCmd);
        $this->assertTrue($this->ownedPidStillAlive((int) $selfPid, $selfCmd));
        // Alive pid with unrelated cmdline => reuse, not a leak.
        $this->assertFalse($this->ownedPidStillAlive((int) $selfPid, 'unrelated-owned-cmdline-xyz'));
    }

    /**
     * @param list<string> $consumeLines
     */
    private function hasAllTransports(array $consumeLines): bool
    {
        $joined = implode("\n", $consumeLines);
        foreach (self::EXPECTED_TRANSPORTS as $transport) {
            if (!str_contains($joined, $transport)) {
                return false;
            }
        }

        return true;
    }

    private function readProcessCmdline(int $pid): string
    {
        if ($pid <= 0) {
            return '';
        }
        $cmd = trim((string) @file_get_contents('/proc/'.$pid.'/cmdline'));
        if ('' !== $cmd) {
            return str_replace("\0", ' ', $cmd);
        }
        $ps = [];
        @exec('ps -p '.escapeshellarg((string) $pid).' -o args= 2>/dev/null', $ps);

        return trim(implode(' ', $ps));
    }

    private function ownedPidStillAlive(int $pid, string $expectedCmdline): bool
    {
        if ($pid <= 0) {
            return false;
        }
        $alive = is_dir('/proc/'.$pid);
        if (!$alive) {
            $ps = [];
            @exec('ps -p '.escapeshellarg((string) $pid).' -o pid= 2>/dev/null', $ps);
            $alive = [] !== $ps && '' !== trim(implode('', $ps));
        }
        if (!$alive) {
            return false;
        }
        $current = $this->readProcessCmdline($pid);
        if ('' === $current) {
            return true;
        }
        if ($current === $expectedCmdline) {
            return true;
        }
        if ('' !== $expectedCmdline && (str_contains($current, $expectedCmdline) || str_contains($expectedCmdline, $current))) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{pid: int, cmdline: string}> $ownedSnapshot
     */
    private function assertOwnedPidsGone(array $ownedSnapshot, int $controllerPid, string $controllerCmdline, float $waitSeconds): void
    {
        $deadline = microtime(true) + $waitSeconds;
        $survivors = [];
        do {
            $survivors = [];
            if ($this->ownedPidStillAlive($controllerPid, $controllerCmdline)) {
                $survivors[] = '#'.$controllerPid.' controller still alive: '.$this->readProcessCmdline($controllerPid);
            }
            foreach ($ownedSnapshot as $row) {
                if ($this->ownedPidStillAlive($row['pid'], $row['cmdline'])) {
                    $survivors[] = '#'.$row['pid'].' '.$row['cmdline'].' (now: '.$this->readProcessCmdline($row['pid']).')';
                }
            }
            if ([] === $survivors) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail(
            "Owned PIDs survived shutdown (pre-captured while controller alive; pgrep -P after exit is not used):\n"
            .implode("\n", $survivors),
        );
    }

    /**
     * @return list<array{pid: int, cmdline: string}>
     */
    private function collectDescendantCmdlines(int $rootPid): array
    {
        $found = [];
        $queue = [$rootPid];
        $seen = [$rootPid => true];
        while ([] !== $queue) {
            $ppid = array_shift($queue);
            $children = [];
            @exec('pgrep -P '.escapeshellarg((string) $ppid).' 2>/dev/null', $children);
            foreach ($children as $childRaw) {
                $child = (int) trim((string) $childRaw);
                if ($child <= 0 || isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $queue[] = $child;
                $found[] = ['pid' => $child, 'cmdline' => $this->readProcessCmdline($child)];
            }
        }

        // Session scan catches separate-PGID messenger children while controller is alive.
        $sidLines = [];
        @exec('ps -o pid=,sid=,args= -p '.escapeshellarg((string) $rootPid).' 2>/dev/null', $sidLines);
        $sid = 0;
        if ([] !== $sidLines) {
            $parts = preg_split('/\s+/', trim($sidLines[0]), 3);
            if (\is_array($parts) && isset($parts[1])) {
                $sid = (int) $parts[1];
            }
        }
        if ($sid > 0) {
            $sessionLines = [];
            @exec('ps -eo pid=,sid=,args= 2>/dev/null', $sessionLines);
            foreach ($sessionLines as $line) {
                $parts = preg_split('/\s+/', trim($line), 3);
                if (!\is_array($parts) || \count($parts) < 3) {
                    continue;
                }
                $pid = (int) $parts[0];
                $lineSid = (int) $parts[1];
                if ($pid === $rootPid || $lineSid !== $sid || isset($seen[$pid])) {
                    continue;
                }
                $seen[$pid] = true;
                $found[] = ['pid' => $pid, 'cmdline' => $parts[2]];
            }
        }

        return $found;
    }

    /**
     * @param list<array{pid: int, cmdline: string}> $rows
     */
    private function formatDescendants(array $rows): string
    {
        if ([] === $rows) {
            return '(none)';
        }
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = '#'.$row['pid'].' '.$row['cmdline'];
        }

        return implode("\n", $lines);
    }
}
