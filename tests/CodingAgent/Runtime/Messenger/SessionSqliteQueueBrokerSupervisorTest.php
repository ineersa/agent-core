<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\SqliteQueue\Client;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor
 */
final class SessionSqliteQueueBrokerSupervisorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('sqlite-queue-broker');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testStartWaitsForReadyAndShutdownStopsOwnedTree(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-1');

        try {
            $supervisor->start($paths);
            $this->assertTrue($supervisor->isReady());
            $this->assertTrue($supervisor->supervise());
            $this->assertFileExists($paths->databasePath);
            $this->assertDirectoryExists($paths->endpointDirectory());
            $this->assertFileExists($paths->endpointPath);

            $stat = stat($paths->endpointDirectory());
            $this->assertNotFalse($stat);
            $this->assertSame(0, $stat['mode'] & 0o077, 'endpoint parent must be private');
            $this->assertSame(posix_geteuid(), $stat['uid']);

            $process = $this->brokerProcess($supervisor);
            $pid = $process->getPid();
            $this->assertNotNull($pid);
            $pgid = posix_getpgid($pid);
            $this->assertNotFalse($pgid);
            $this->assertNotSame(posix_getpgid(getmypid()), $pgid, 'broker posix_setsid must isolate PGID from the controller');
            $this->assertFalse($this->processCommandLineContains($pid, 'setsid'), 'launch must not require an external setsid binary');
            $driverPids = $this->descendantPids($pid);
            $this->assertNotSame([], $driverPids, 'Amp SQLite driver child must exist after ready');
        } finally {
            $supervisor->shutdown();
        }

        $this->assertFalse($supervisor->isReady());
        clearstatcache(true, $paths->endpointPath);
        $this->assertFalse(file_exists($paths->endpointPath), 'normal shutdown must remove owned socket');
        $this->assertFileExists($paths->databasePath, 'queue database must survive ordinary shutdown');
    }

    public function testForcedBrokerDeathStopsOwnedDriverChildren(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 0.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-kill');

        try {
            $supervisor->start($paths);
            $process = $this->brokerProcess($supervisor);
            $pid = $process->getPid();
            $this->assertNotNull($pid);
            $pgid = posix_getpgid($pid);
            $this->assertNotFalse($pgid);
            $this->assertNotSame(posix_getpgid(getmypid()), $pgid);
            $driverPids = $this->descendantPids($pid);
            $this->assertNotSame([], $driverPids);

            // Escalate owned group immediately (grace=0). Driver children must exit.
            $supervisor->shutdown();

            foreach ($driverPids as $driverPid) {
                $this->assertFalse($this->pidAlive($driverPid), 'owned Amp driver must exit with forced broker death');
            }
            $this->assertFalse($process->isRunning());
        } finally {
            $supervisor->shutdown();
        }
    }

    public function testRefuseExistingEndpointDoesNotUnlinkForeignSocketOnShutdown(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-2');
        mkdir($paths->endpointDirectory(), 0o700, true);
        touch($paths->endpointPath);

        try {
            try {
                $supervisor->start($paths);
                $this->fail('Expected refuse of existing endpoint.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('endpoint already exists', $exception->getMessage());
            }

            // Controller catch paths call shutdown after start failures.
            $supervisor->shutdown();
            $this->assertFileExists($paths->endpointPath, 'refused foreign/pre-existing endpoint must survive shutdown');
        } finally {
            @unlink($paths->endpointPath);
            @rmdir($paths->endpointDirectory());
        }
    }

    public function testBrokerLaunchInheritsProcessEnvironmentWhenEnvSuperglobalEmpty(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-env');

        $previousEnv = $_ENV;
        $_ENV = [];
        try {
            $this->assertNotFalse(getenv('HOME'));
            // Empty $_ENV must not strip HOME from the broker child.
            $supervisor->start($paths);
            $this->assertTrue($supervisor->isReady());
        } finally {
            $_ENV = $previousEnv;
            $supervisor->shutdown();
        }
    }

    public function testControllerOwnerEofStopsBrokerHelperAndAllowsDurableDbRestart(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $first = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-owner-eof');

        $first->start($paths);
        $process = $this->brokerProcess($first);
        $pid = $process->getPid();
        $this->assertNotNull($pid);
        $driverPids = $this->descendantPids($pid);
        $this->assertNotSame([], $driverPids, 'Amp SQLite driver child must exist after ready');

        $ownerInput = (new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'ownerInput'))->getValue($first);
        $this->assertInstanceOf(\Symfony\Component\Process\InputStream::class, $ownerInput);
        // Hard controller death closes the owner write end without graceful shutdown.
        $ownerInput->close();

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && $process->isRunning()) {
            usleep(20_000);
        }
        $this->assertFalse($process->isRunning(), 'broker must exit when controller owner stdin closes');
        foreach ($driverPids as $driverPid) {
            $this->assertFalse($this->pidAlive($driverPid), 'owned Amp helper must exit with controller hard death');
        }

        $restart = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        try {
            $restartPaths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-owner-eof');
            $this->assertSame($paths->databasePath, $restartPaths->databasePath);
            $restart->start($restartPaths);
            $this->assertTrue($restart->isReady(), 'durable queue DB must restart after owner EOF released lifetime locks');
        } finally {
            $restart->shutdown();
            $first->shutdown();
        }
    }

    public function testShutdownUntilHonorsSharedDeadlineEscalation(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-shared-deadline');

        $supervisor->start($paths);
        $process = $this->brokerProcess($supervisor);
        $this->assertTrue($process->isRunning());

        $started = microtime(true);
        $supervisor->shutdownUntil($started);
        $elapsed = microtime(true) - $started;

        $this->assertFalse($process->isRunning());
        $this->assertLessThan(1.0, $elapsed, 'shared deadline already expired must escalate without waiting full grace');
        $this->assertFalse($supervisor->isReady());
    }

    public function testClientSendRenewAndAckWithoutSecondClaim(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0, shutdownGraceSeconds: 5.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', $this->tmpDir, 'broker-client');

        $owner = null;
        $other = null;
        try {
            $supervisor->start($paths);
            $owner = Client::connect($paths->endpointPath);
            $other = Client::connect($paths->endpointPath);

            $owner->send('jobs', 'body', '{}');
            $delivery = $owner->receive('jobs');
            $this->assertNotNull($delivery);
            $deadline = $owner->keepalive($delivery->receipt);
            $this->assertGreaterThan(0, $deadline);
            $this->assertNull($other->receive('jobs'), 'active reservation must block a second claim');
            $owner->acknowledge($delivery->receipt);
            $this->assertNull($other->receive('jobs'));
        } finally {
            $owner?->close();
            $other?->close();
            $supervisor->shutdown();
        }
    }

    private function runtimeConfig(): RuntimeProcessConfig
    {
        $locator = $this->createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn(\dirname(__DIR__, 4).'/bin/console');
        $locator->method('command')->willReturn([\PHP_BINARY, \dirname(__DIR__, 4).'/bin/console']);

        return new RuntimeProcessConfig($locator, $this->tmpDir);
    }

    private function brokerProcess(SessionSqliteQueueBrokerSupervisor $supervisor): \Symfony\Component\Process\Process
    {
        $property = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'process');
        $process = $property->getValue($supervisor);
        $this->assertInstanceOf(\Symfony\Component\Process\Process::class, $process);

        return $process;
    }

    /**
     * @return list<int>
     */
    private function descendantPids(int $pid): array
    {
        $output = [];
        exec('pgrep -P '.escapeshellarg((string) $pid), $output);
        $pids = [];
        foreach ($output as $line) {
            $child = (int) trim($line);
            if ($child > 0) {
                $pids[] = $child;
            }
        }

        return $pids;
    }

    private function pidAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return @posix_kill($pid, 0);
    }

    private function processCommandLineContains(int $pid, string $needle): bool
    {
        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (false === $cmdline) {
            return false;
        }

        return str_contains(str_replace("\0", ' ', $cmdline), $needle);
    }
}
