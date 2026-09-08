<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: isAlive must not treat PHP-stat-cached /proc rows or unreaped
 * zombies as running processes.
 */
#[CoversClass(ProcessLifecycle::class)]
final class ProcessLifecycleTest extends TestCase
{
    private string $tmpDir;
    private ProcessLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('process-lifecycle');
        $this->lifecycle = new ProcessLifecycle(
            new BackgroundProcessConfig(storageDir: $this->tmpDir, stopGraceSeconds: 0),
            new TestLogger(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function isAliveRejectsNonPositivePids(): void
    {
        $this->assertFalse($this->lifecycle->isAlive(0));
        $this->assertFalse($this->lifecycle->isAlive(-1));
    }

    #[Test]
    public function launchProcessTracksWrapperPidNotTransientLauncher(): void
    {
        $pidFile = $this->tmpDir.'/wrapper.pid';
        $statusFile = $this->tmpDir.'/wrapper.status';
        $logFile = $this->tmpDir.'/wrapper.log';
        $ready = $this->tmpDir.'/ready';
        $hold = $this->tmpDir.'/hold';

        $command = \sprintf(
            'echo ready > %s; while [ ! -f %s ]; do sleep 0.01; done; echo done',
            escapeshellarg($ready),
            escapeshellarg($hold),
        );

        $launch = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);

        try {
            $deadline = hrtime(true) + 2_000_000_000;
            while (hrtime(true) < $deadline && !is_file($ready)) {
                usleep(1_000);
            }

            $this->assertFileExists($ready, 'Workload must start under the tracked wrapper');
            $this->assertFileExists($pidFile);
            $this->assertSame(
                $launch['pid'],
                (int) trim((string) file_get_contents($pidFile)),
                'Tracked PID must match the wrapper PID file',
            );
            $this->assertTrue($this->lifecycle->isAlive($launch['pid']));
            $this->assertNull($this->lifecycle->readStatusFile($statusFile));
        } finally {
            file_put_contents($hold, 'x');
            $deadline = hrtime(true) + 2_000_000_000;
            while (hrtime(true) < $deadline && null === $this->lifecycle->readStatusFile($statusFile)) {
                usleep(1_000);
            }
            if ($this->lifecycle->isAlive($launch['pid'])) {
                $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
            }
        }

        $this->assertSame(0, $this->lifecycle->readStatusFile($statusFile));
        $this->assertStringContainsString('done', (string) file_get_contents($logFile));
    }

    #[Test]
    public function pidPathHelpersReadWrapperSidecar(): void
    {
        $statusPath = $this->tmpDir.'/abc.status';
        $pidPath = $this->tmpDir.'/abc.pid';
        file_put_contents($pidPath, "4321\n");

        $this->assertSame($pidPath, $this->lifecycle->pidPathForStatusPath($statusPath));
        $this->assertSame(4321, $this->lifecycle->readPidFile($pidPath));
        $this->assertNull($this->lifecycle->readPidFile($this->tmpDir.'/missing.pid'));
        $this->assertNull($this->lifecycle->pidPathForStatusPath($this->tmpDir.'/abc.log'));
    }

    #[Test]
    public function logTailPreservesUtf8BoundariesAndNewestOutput(): void
    {
        $box = "\u{2500}";
        $log = $this->tmpDir.'/unicode.log';
        $text = str_repeat($box, 10).'END';
        file_put_contents($log, $text);

        foreach ([7, 8, 9] as $budget) {
            $result = $this->lifecycle->readLogTail($log, $budget);

            $this->assertTrue(mb_check_encoding($result->content, 'UTF-8'));
            $this->assertTrue($result->truncated);
            $this->assertSame(\strlen($text), $result->totalBytes);
            $this->assertLessThanOrEqual($budget, \strlen($result->content));
            $this->assertSame(str_repeat($box, intdiv($budget - 3, 3)).'END', $result->content);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function exitedChildren(): iterable
    {
        yield 'reaped child with warm stat cache' => [true];
        yield 'unreaped zombie' => [false];
    }

    #[Test]
    #[DataProvider('exitedChildren')]
    public function isAliveRecognizesExitedChild(bool $reap): void
    {
        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, '-r', 'fread(STDIN, 1);'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        try {
            $status = proc_get_status($process);
            $pid = $status['pid'];
            $this->assertGreaterThan(0, $pid);
            $this->assertTrue($this->lifecycle->isAlive($pid));

            // Closing stdin releases the child. No delayed fixture or signal race.
            fclose($pipes[0]);
            if ($reap) {
                $this->assertSame(0, proc_close($process));
                $this->assertFalse($this->lifecycle->isAlive($pid));

                return;
            }

            // Keep ownership without waitpid/proc_get_status, which would reap it.
            $deadline = hrtime(true) + 2_000_000_000;
            $becameZombie = false;
            while (hrtime(true) < $deadline) {
                $stat = @file_get_contents('/proc/'.$pid.'/stat');
                if (false !== $stat) {
                    $closeParen = strrpos($stat, ')');
                    $state = false === $closeParen ? '' : ($stat[$closeParen + 2] ?? '');
                    if ('Z' === $state) {
                        $becameZombie = true;
                        break;
                    }
                }
                usleep(1_000);
            }

            $this->assertTrue($becameZombie, 'Child must become a zombie before reaping');
            $this->assertFalse(
                $this->lifecycle->isAlive($pid),
                'Unreaped zombie /proc entries must not count as alive',
            );
        } finally {
            if (\is_resource($process)) {
                proc_terminate($process, \SIGKILL);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }
}
