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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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
            new LockFactory(new FlockStore()),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function delayedWrapperDoesNotExecuteAfterLaunchReaderDies(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('This proof never signals a root-owned process.');
        }
        $this->assertSame($this->tmpDir, realpath($this->tmpDir));
        $setsid = (new ExecutableFinder())->find('setsid');
        $this->assertNotNull($setsid);
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $shim = $this->tmpDir.'/setsid';
        // Delay the actual wrapper behind a socket barrier. --wait lets the
        // fixture report its termination, not merely the transient launcher exit.
        $shimBody = <<<'PHP'
$socket = stream_socket_client(ADDRESS);
fwrite($socket, "entered\n");
fgets($socket);
$process = proc_open([SETSID, '--wait', ...array_slice($argv, 1)], [STDIN, STDOUT, STDERR], $pipes);
$exit = proc_close($process);
fwrite($socket, "done:$exit\n");
PHP;
        $shimBody = str_replace(['ADDRESS', 'SETSID'], [var_export('tcp://'.$address, true), var_export($setsid, true)], $shimBody);
        file_put_contents($shim, '#!'.\PHP_BINARY."\n<?php\n".$shimBody);
        chmod($shim, 0700);
        $workerPath = $this->tmpDir.'/worker.php';
        $marker = $this->tmpDir.'/must-not-execute';
        $workerBody = <<<'PHP'
<?php
require AUTOLOAD;
$config = new \Ineersa\CodingAgent\Config\BackgroundProcessConfig(storageDir: DIRECTORY);
$lifecycle = new \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle($config, new \Psr\Log\NullLogger(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()));
$lifecycle->launchProcess(COMMAND, PIDFILE, LOGFILE, STATUSFILE);
PHP;
        $workerBody = str_replace(
            ['AUTOLOAD', 'DIRECTORY', 'COMMAND', 'PIDFILE', 'LOGFILE', 'STATUSFILE'],
            array_map(static fn (string $value): string => var_export($value, true), [\dirname(__DIR__, 4).'/vendor/autoload.php', $this->tmpDir, 'printf forbidden > '.escapeshellarg($marker), $this->tmpDir.'/late.pid', $this->tmpDir.'/late.log', $this->tmpDir.'/late.status']),
            $workerBody,
        );
        file_put_contents($workerPath, $workerBody);
        $worker = new Process([\PHP_BINARY, $workerPath], env: ['PATH' => $this->tmpDir.':'.getenv('PATH'), 'HATFIELD_SESSION_ID' => false], timeout: 5);
        $connection = null;
        try {
            $worker->start();
            $connection = stream_socket_accept($server, 2);
            $this->assertIsResource($connection);
            stream_set_timeout($connection, 2);
            $this->assertSame("entered\n", fgets($connection));
            $pid = $worker->getPid();
            $this->assertNotNull($pid);
            $this->assertStringNotContainsString('HATFIELD_SESSION_ID=', (string) file_get_contents('/proc/'.$pid.'/environ'));
            $worker->signal(\SIGKILL);
            $worker->wait();
            fwrite($connection, "release\n");
            $this->assertStringStartsWith('done:', (string) fgets($connection));
            $this->assertFileExists($this->tmpDir.'/late.pid', 'The wrapper reached its native claim after the reader died.');
            $this->assertFileDoesNotExist($marker, 'Failed readiness write must prevent workload execution.');
        } finally {
            if (\is_resource($connection)) {
                fclose($connection);
            }
            fclose($server);
            if ($worker->isRunning()) {
                $worker->stop(0);
            }
        }
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
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $command = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg(
            '$socket = stream_socket_client("tcp://'.$address.'"); fread($socket, 1); echo "done";',
        );

        $launch = $this->lifecycle->launchProcess($command, $pidFile, $logFile, $statusFile);
        $connection = null;
        try {
            $connection = stream_socket_accept($server, 2);
            $this->assertIsResource($connection, 'Workload must connect under the tracked wrapper');
            $this->assertFileExists($pidFile);
            $this->assertSame(
                $launch['pid'],
                (int) trim((string) file_get_contents($pidFile)),
                'Tracked PID must match the wrapper PID file',
            );
            $this->assertTrue($this->lifecycle->isAlive($launch['pid']));
            $this->assertNull($this->lifecycle->readStatusFile($statusFile));
        } finally {
            if (\is_resource($connection)) {
                fwrite($connection, 'x');
                fclose($connection);
            }
            fclose($server);
            $deadline = hrtime(true) + 2_000_000_000;
            while (hrtime(true) < $deadline && null === $this->lifecycle->readStatusFile($statusFile)) {
                usleep(1_000);
            }
            if ($this->lifecycle->isAlive($launch['pid'])) {
                $this->lifecycle->withSidecarLock($statusFile, fn () => $this->lifecycle->sendKill($launch['pid'], $launch['pgid'], $statusFile));
            }
        }

        $this->assertSame(0, $this->lifecycle->readStatusFile($statusFile));
        $this->assertStringContainsString('done', (string) file_get_contents($logFile));
    }

    #[Test]
    public function commandExitAndHeredocCannotBypassStatusRecording(): void
    {
        $statusPath = $this->tmpDir.'/command.status';
        $logPath = $this->tmpDir.'/command.log';
        $launch = $this->lifecycle->launchProcess("cat <<'END'\nheredoc output\nEND\nexit 7", $this->tmpDir.'/command.pid', $logPath, $statusPath);
        try {
            $deadline = hrtime(true) + 2_000_000_000;
            while (hrtime(true) < $deadline && null === $this->lifecycle->readStatusFile($statusPath)) {
                usleep(1_000);
            }
            $this->assertSame(7, $this->lifecycle->readStatusFile($statusPath));
            $this->assertSame("heredoc output\n", file_get_contents($logPath));
        } finally {
            if ($this->lifecycle->isAlive($launch['pid'])) {
                $this->lifecycle->withSidecarLock($statusPath, fn () => $this->lifecycle->sendKill($launch['pid'], $launch['pgid'], $statusPath));
            }
        }
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
