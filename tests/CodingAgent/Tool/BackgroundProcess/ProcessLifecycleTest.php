<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Support\TestProcessRuntime;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
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
            TestProcessRuntime::source(),
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
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $shim = $this->tmpDir.'/launcher.php';
        // Delay the internal launcher behind a socket barrier. The stderr
        // pipe stays open until the detached wrapper fails or redirects.
        $shimBody = <<<'PHP'
$socket = stream_socket_client(ADDRESS);
fwrite($socket, "entered\n");
fgets($socket);
$process = proc_open([PHP_BINARY, CONSOLE, ...array_slice($argv, 1)], [STDIN, STDOUT, ['pipe', 'w']], $pipes);
stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($process);
fwrite($socket, "done:$exit\n");
PHP;
        $shimBody = str_replace(['ADDRESS', 'CONSOLE'], [var_export('tcp://'.$address, true), var_export(\dirname(__DIR__, 4).'/bin/console', true)], $shimBody);
        file_put_contents($shim, '#!'.\PHP_BINARY."\n<?php\n".$shimBody);
        chmod($shim, 0700);
        $workerPath = $this->tmpDir.'/worker.php';
        $marker = $this->tmpDir.'/must-not-execute';
        $workerBody = <<<'PHP'
<?php
require AUTOLOAD;
$config = new \Ineersa\CodingAgent\Config\BackgroundProcessConfig(storageDir: DIRECTORY);
$runtime = new \Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig(new \Ineersa\CodingAgent\Runtime\Process\ConfigExecutableLocator(), DIRECTORY);
$lifecycle = new \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle($config, new \Psr\Log\NullLogger(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()), $runtime);
$lifecycle->launchProcess(COMMAND, PIDFILE, LOGFILE, STATUSFILE);
PHP;
        $workerBody = str_replace(
            ['AUTOLOAD', 'DIRECTORY', 'COMMAND', 'PIDFILE', 'LOGFILE', 'STATUSFILE'],
            array_map(static fn (string $value): string => var_export($value, true), [\dirname(__DIR__, 4).'/vendor/autoload.php', $this->tmpDir, 'printf forbidden > '.escapeshellarg($marker), $this->tmpDir.'/late.pid', $this->tmpDir.'/late.log', $this->tmpDir.'/late.status']),
            $workerBody,
        );
        file_put_contents($workerPath, $workerBody);
        $worker = new Process([\PHP_BINARY, $workerPath], env: ['HATFIELD_BINARY_PATH' => $shim, 'HATFIELD_SESSION_ID' => false], timeout: 5);
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
    public function launchReportsDetachedExecFailureWithoutClaimingWork(): void
    {
        $brokenBash = $this->tmpDir.'/bash';
        file_put_contents($brokenBash, "not an executable format\n");
        chmod($brokenBash, 0700);
        $path = getenv('PATH');
        putenv('PATH='.$this->tmpDir.':'.$path);
        $pidFile = $this->tmpDir.'/failed.pid';
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('readiness: pcntl_exec failed');
            $this->lifecycle->launchProcess('exit 0', $pidFile, $this->tmpDir.'/failed.log', $this->tmpDir.'/failed.status');
        } finally {
            putenv(false === $path ? 'PATH' : 'PATH='.$path);
            $this->assertFileDoesNotExist($pidFile);
        }
    }

    #[Test]
    public function observeExitTrustsStatusOnlyOnceTheWrapperIsGone(): void
    {
        $status = $this->tmpDir.'/observe.status';
        $wrapper = proc_open(['cat'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($wrapper);
        $pid = proc_get_status($wrapper)['pid'];
        try {
            $this->assertFalse($this->lifecycle->observeExit($status, $pid)->exited, 'A live wrapper without status is running.');
            file_put_contents($status, '-1');
            $this->assertFalse($this->lifecycle->observeExit($status, $pid)->exited, 'A stop marker on a live wrapper is only a signal attempt.');
            $this->assertFalse($this->lifecycle->observeExit($status, null)->exited, 'Missing PID evidence is not exit.');
        } finally {
            array_map(fclose(...), $pipes); // EOF ends cat; proc_close reaps it.
            proc_close($wrapper);
        }

        file_put_contents($status, '0 0');
        $finished = $this->lifecycle->observeExit($status, $pid);
        $this->assertTrue($finished->exited);
        $this->assertSame(0, $finished->exit?->exitCode, 'A dead wrapper reports the status it wrote before exiting.');

        unlink($status);
        $unknown = $this->lifecycle->observeExit($status, $pid);
        $this->assertTrue($unknown->exited, 'A wrapper killed before writing status has exited.');
        $this->assertNull($unknown->exit, 'Its outcome stays unknown.');
    }

    #[Test]
    public function isAliveRejectsNonPositivePids(): void
    {
        $this->assertFalse($this->lifecycle->isAlive(0));
        $this->assertFalse($this->lifecycle->isAlive(-1));
    }

    #[Test]
    public function resolvePgidRejectsNonPositivePidsRatherThanResolvingTheCurrentGroup(): void
    {
        $this->assertNull($this->lifecycle->resolvePgid(0));
        $this->assertNull($this->lifecycle->resolvePgid(-1));
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
            $this->assertSame($launch['pid'], $launch['pgid'], 'Readiness is published after the wrapper enters its own group');
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
    #[TestWith([0, false])]
    #[TestWith([3, false])]
    #[TestWith([0, true])]
    public function boundedOutputRecordsWriterExitAndCleansFifo(int $writerExit, bool $failFifo): void
    {
        $this->assertSame($this->tmpDir, realpath($this->tmpDir));
        $shim = $this->tmpDir.'/runtime.php';
        if ($failFifo) {
            file_put_contents($this->tmpDir.'/mkfifo', "#!/bin/sh\nexit 1\n");
            chmod($this->tmpDir.'/mkfifo', 0700);
        }
        // Run the real wrapper synchronously in place of detaching it, so
        // launch returns after status publication without polling or signals.
        file_put_contents($shim, str_replace('WRITER_EXIT', (string) $writerExit, <<<'PHP'
<?php
if ($argv[1] === '--internal-bash-log-writer') {
    stream_copy_to_stream(STDIN, STDOUT);
    exit(WRITER_EXIT);
}
putenv('PATH='.__DIR__.':'.getenv('PATH'));
passthru(escapeshellarg($argv[2]).' -c '.escapeshellarg($argv[3]));
PHP));
        $locator = new class($shim) implements AppExecutableLocator {
            public function __construct(private readonly string $shim)
            {
            }

            public function command(): array
            {
                return [\PHP_BINARY, $this->shim];
            }

            public function path(): string
            {
                return $this->shim;
            }
        };
        $lifecycle = new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $this->tmpDir), new TestLogger(), new LockFactory(new FlockStore()), new RuntimeProcessConfig($locator, $this->tmpDir));
        $pid = $this->tmpDir.'/command.pid';
        $log = $this->tmpDir.'/command.log';
        $status = $this->tmpDir.'/command.status';
        if ($failFifo) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('readiness: writer-setup-failed');
        }
        try {
            $lifecycle->launchProcess('printf "stdout"; printf "stderr" >&2; exit 7', $pid, $log, $status, boundBashOutput: true);
            $exit = $lifecycle->readNativeExit($status);
            $this->assertNotNull($exit);
            $this->assertSame(7, $exit->exitCode);
            $this->assertSame($writerExit, $exit->logWriterExitCode);
            $this->assertSame('stdoutstderr', file_get_contents($log));
        } finally {
            $this->assertSame([], glob($this->tmpDir.'/.bash-writer.*'));
            if ($failFifo) {
                $this->assertFileDoesNotExist($pid);
                $this->assertFileDoesNotExist($status);
                $this->assertSame('', file_get_contents($log));
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
