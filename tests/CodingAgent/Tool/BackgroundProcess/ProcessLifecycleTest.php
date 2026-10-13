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
use Symfony\Component\Process\InputStream;
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

    /** @return iterable<string, array{string}> */
    public static function unprovenAutomaticScopes(): iterable
    {
        foreach (['legacy', 'foreign', 'wrong-session', 'invalid-marker', 'wrong-status'] as $case) {
            yield $case => [$case];
        }
    }

    #[Test]
    #[DataProvider('unprovenAutomaticScopes')]
    public function automaticCleanupNeverCallsStopForUnprovenScope(string $case): void
    {
        if ('legacy' === $case) {
            $log = $this->tmpDir.'/legacy.log';
            $status = $this->tmpDir.'/legacy.status';
        } else {
            $lifecycle = 'foreign' === $case
                ? new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $this->tmpDir.'/foreign'), new TestLogger())
                : $this->lifecycle;
            $launch = $lifecycle->allocateManagedLaunch('wrong-session' === $case ? 'other-session' : 'session');
            $log = $launch->logPath;
            $status = $launch->statusPath;
            if ('invalid-marker' === $case) {
                file_put_contents(\dirname($log).'/owner.json', '{}');
            } elseif ('wrong-status' === $case) {
                $status = \dirname($log).'/wrong.status';
            }
        }
        file_put_contents($log, 'retained');
        $called = false;
        $this->assertFalse($this->lifecycle->cleanupManagedRecordSidecars($log, $status, 'session', static function () use (&$called): void {
            $called = true;
        }));
        $this->assertFalse($called, 'Ownership must be established before the automatic stop callback.');
        $this->assertFileExists($log);
    }

    #[Test]
    public function automaticStopHoldsCoordinatorAndRetainsLastWriter(): void
    {
        $launch = $this->lifecycle->allocateManagedLaunch('session');
        $log = $launch->logPath;
        $status = $launch->statusPath;
        $coordinator = (new LockFactory(new FlockStore($this->tmpDir)))->createLock('background-artifact-lifecycle', ttl: null);
        $calls = 0;
        $stop = function () use ($coordinator, &$calls): void {
            ++$calls;
            $this->assertFalse($coordinator->acquire(false), 'The validated scope must remain coordinated through stop.');
        };
        $this->assertFalse($this->lifecycle->cleanupManagedRecordSidecars($log, $status, 'session', $stop));
        $this->assertSame(1, $calls, 'A managed live writer permits stop, but not premature removal.');
        $this->assertDirectoryExists(\dirname($log));
        $launch = null;
        $this->assertTrue($this->lifecycle->cleanupManagedRecordSidecars($log, $status, 'session', $stop));
        $this->assertSame(2, $calls);
        $this->assertDirectoryDoesNotExist(\dirname($log));
        $this->assertFalse($this->lifecycle->cleanupManagedRecordSidecars($log, $status, 'session', $stop));
        $this->assertSame(2, $calls, 'An already removed scope cannot authorize another stop.');
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
                $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
            }
        }

        $this->assertSame(0, $this->lifecycle->readStatusFile($statusFile));
        $this->assertStringContainsString('done', (string) file_get_contents($logFile));
    }

    #[Test]
    public function shellWrapperRetainsLeaseAfterProducerClosesItsDescriptor(): void
    {
        $factory = new LockFactory(new FlockStore($this->tmpDir));
        $producer = $factory->createLock('writer', ttl: null, autoRelease: false);
        $this->assertTrue($producer->acquire(false));
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $command = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg(
            '$s = stream_socket_client("tcp://'.$address.'"); fwrite($s, "r"); fread($s, 1);',
        );
        $statusPath = $this->tmpDir.'/lease.status';
        $launch = $this->lifecycle->launchProcess($command, $this->tmpDir.'/lease.pid', $this->tmpDir.'/lease.log', $statusPath);
        $connection = null;
        $probe = $factory->createLock('writer', ttl: null);
        try {
            $connection = stream_socket_accept($server, 2);
            $this->assertIsResource($connection);
            $this->assertSame('r', fread($connection, 1));
            $producer = null;
            $this->assertFalse($probe->acquire(false), 'The actual setsid/bash writer must retain the inherited lease.');
            fwrite($connection, 'x');
            fclose($connection);
            $connection = null;
            $deadline = hrtime(true) + 2_000_000_000;
            while (!$probe->acquire(false) && hrtime(true) < $deadline) {
                usleep(1_000);
            }
            $this->assertTrue($probe->isAcquired(), 'Lease must become available after the last shell writer exits.');
            $this->assertSame(0, $this->lifecycle->readStatusFile($statusPath));
        } finally {
            $probe->release();
            $producer = null;
            if (\is_resource($connection)) {
                fwrite($connection, 'x');
                fclose($connection);
            }
            fclose($server);
            if ($this->lifecycle->isAlive($launch['pid'])) {
                $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
            }
        }
    }

    #[Test]
    public function failedLaunchStopRejectsMismatchedStartIdentityWithoutSignaling(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $command = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg('$s = stream_socket_client("tcp://'.$address.'"); fwrite($s, "r"); fread($s, 1);');
        $status = $this->tmpDir.'/identity.status';
        $launch = $this->lifecycle->launchProcess($command, $this->tmpDir.'/identity.pid', $this->tmpDir.'/identity.log', $status);
        $connection = null;
        try {
            $connection = stream_socket_accept($server, 2);
            $this->assertIsResource($connection);
            $this->assertSame('r', fread($connection, 1));
            $this->assertNotNull($launch['start_identity']);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed launch process identity is no longer verifiable.');
            $this->lifecycle->stopOwnedProcess($launch['pid'], $launch['pgid'], $status, 0, $launch['start_identity'].'0');
        } finally {
            $stillAlive = $this->lifecycle->isAlive($launch['pid']);
            if (\is_resource($connection)) {
                fwrite($connection, 'x');
                fclose($connection);
            }
            fclose($server);
            $deadline = hrtime(true) + 2_000_000_000;
            while (null === $this->lifecycle->readStatusFile($status) && hrtime(true) < $deadline) {
                usleep(1_000);
            }
            $this->assertTrue($stillAlive, 'An identity mismatch must leave the unrelated generation untouched.');
        }
    }

    #[Test]
    public function producerDeathBeforeRowInsertionRetainsActualShellUntilLastWrite(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $command = escapeshellarg(\PHP_BINARY).' -r '.escapeshellarg('$s = stream_socket_client("tcp://'.$address.'"); fwrite($s, "r"); fread($s, 1); echo "last write";');
        $script = $this->tmpDir.'/producer.php';
        file_put_contents($script, '<?php require '.var_export(\dirname(__DIR__, 4).'/vendor/autoload.php', true).';'.<<<'PHP'
$lifecycle = new \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle(new \Ineersa\CodingAgent\Config\BackgroundProcessConfig(storageDir: $argv[1]), new \Psr\Log\NullLogger());
$launch = $lifecycle->allocateManagedLaunch('failed-producer');
$lifecycle->launchProcess($argv[2], $launch->pidPath, $launch->logPath, $launch->statusPath);
echo json_encode(['log' => $launch->logPath, 'status' => $launch->statusPath]), "\n";
fread(STDIN, 1);
exit(67);
PHP);
        $input = new InputStream();
        $producer = new Process([\PHP_BINARY, $script, $this->tmpDir, $command], env: ['HATFIELD_SESSION_ID' => false], input: $input, timeout: 5);
        $connection = null;
        try {
            $producer->start();
            $this->assertTrue($producer->waitUntil(static fn (): bool => str_contains($producer->getOutput(), "\n")), $producer->getErrorOutput());
            $paths = json_decode(trim($producer->getOutput()), true, flags: \JSON_THROW_ON_ERROR);
            $connection = stream_socket_accept($server, 2);
            $this->assertIsResource($connection);
            $this->assertSame('r', fread($connection, 1));
            $input->write('x');
            $this->assertSame(67, $producer->wait());
            $removed = 0;
            $visitor = function (string $log, string $status) use (&$removed): bool {
                $this->assertSame(0, $this->lifecycle->readStatusFile($status));
                $this->assertSame('last write', file_get_contents($log));
                ++$removed;

                return true;
            };
            $this->lifecycle->visitStoppedManagedArtifacts($visitor);
            $this->assertSame(0, $removed, 'Producer death cannot reclaim the surviving shell writer.');
            $this->assertNull($this->lifecycle->readStatusFile($paths['status']));
            fwrite($connection, 'x');
            fclose($connection);
            $connection = null;
            $deadline = hrtime(true) + 2_000_000_000;
            do {
                $this->lifecycle->visitStoppedManagedArtifacts($visitor);
                if (0 === $removed) {
                    usleep(1_000);
                }
            } while (0 === $removed && hrtime(true) < $deadline);
            $this->assertSame(1, $removed);
            $this->assertDirectoryDoesNotExist(\dirname($paths['log']));
        } finally {
            $input->close();
            if (\is_resource($connection)) {
                fwrite($connection, 'x');
                fclose($connection);
            }
            fclose($server);
            if ($producer->isRunning()) {
                $producer->stop(0);
            }
        }
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
                $this->lifecycle->sendKill($launch['pid'], $launch['pgid']);
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
