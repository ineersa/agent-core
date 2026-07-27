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

            // runtime.ready is emitted before ConsumerSupervisor launches — wait for transports.
            $consumeLines = [];
            $transportDeadline = microtime(true) + 15.0;
            $lastDump = '';
            while (microtime(true) < $transportDeadline) {
                $descendants = $this->collectDescendantCmdlines((int) $pid);
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
        } finally {
            if (null !== $process && $process->isRunning()) {
                $pid = $process->getPid();
                $process->stop(5.0, \SIGTERM);
                if (null !== $pid && $pid > 0) {
                    usleep(200_000);
                    $leftovers = $this->collectDescendantCmdlines((int) $pid);
                    $owned = [];
                    foreach ($leftovers as $row) {
                        if (
                            str_contains($row['cmdline'], $binary)
                            || str_contains($row['cmdline'], 'messenger:consume')
                            || str_contains($row['cmdline'], basename($binary))
                        ) {
                            $owned[] = '#'.$row['pid'].' '.$row['cmdline'];
                        }
                    }
                    if (is_dir('/proc/'.$pid)) {
                        $owned[] = '#'.$pid.' controller still alive';
                    }
                    $this->assertSame(
                        [],
                        $owned,
                        "Owned descendants survived shutdown:\n".implode("\n", $owned),
                    );
                }
            }
            TestDirectoryIsolation::removeDirectory($tmp);
        }
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
                $cmd = trim((string) @file_get_contents('/proc/'.$child.'/cmdline'));
                if ('' === $cmd) {
                    $ps = [];
                    @exec('ps -p '.escapeshellarg((string) $child).' -o args= 2>/dev/null', $ps);
                    $cmd = trim(implode(' ', $ps));
                } else {
                    $cmd = str_replace("\0", ' ', $cmd);
                }
                $found[] = ['pid' => $child, 'cmdline' => $cmd];
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
