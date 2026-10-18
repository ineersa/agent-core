<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerTopology;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\OwnedProcessTree;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\SqliteQueue\Client;
use Ineersa\SqliteQueue\Exception\TransportException;
use Ineersa\SqliteQueue\Messenger\BrokerConnection;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\Protocol\Limits;
use Ineersa\SqliteQueue\ValueObject\QueueName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

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
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-1');

        try {
            $supervisor->start($paths);
            $this->assertTrue($this->brokerProcess($supervisor)->isRunning());
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
            $driverTree = OwnedProcessTree::descendants($pid);
            $driverPids = array_column($driverTree, 'pid');
            $this->assertActualAmpWorker($driverTree);
            $this->assertNotSame([], $driverPids, 'Amp SQLite driver child must exist after ready');
            $workerSeen = false;
            foreach ($driverPids as $driverPid) {
                if ($this->processCommandLineContains($driverPid, '--internal-sqlite-worker')) {
                    $workerSeen = true;
                    break;
                }
            }
            $this->assertTrue($workerSeen, 'Amp SQLite worker must relaunch through --internal-sqlite-worker');
        } finally {
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }

        foreach ($driverTree as $driver) {
            $this->assertFalse(OwnedProcessTree::stillAlive($driver), 'Entire Amp worker tree must exit after normal shutdown');
        }
        $this->assertFalse($this->brokerProcessAlive($supervisor));
        clearstatcache(true, $paths->endpointPath);
        $this->assertFalse(file_exists($paths->endpointPath), 'normal shutdown must remove owned socket');
        $this->assertFileExists($paths->databasePath, 'queue database must survive ordinary shutdown');
    }

    public function testForcedBrokerDeathStopsOwnedDriverChildren(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-kill');

        try {
            $supervisor->start($paths);
            $process = $this->brokerProcess($supervisor);
            $pid = $process->getPid();
            $this->assertNotNull($pid);
            $pgid = posix_getpgid($pid);
            $this->assertNotFalse($pgid);
            $this->assertNotSame(posix_getpgid(getmypid()), $pgid);
            $driverTree = OwnedProcessTree::descendants($pid);
            $driverPids = array_column($driverTree, 'pid');
            $this->assertActualAmpWorker($driverTree);
            $this->assertNotSame([], $driverPids);

            // Escalate owned group immediately. Driver children must exit.
            $supervisor->shutdownUntil(microtime(true));

            foreach ($driverTree as $driver) {
                $this->assertFalse(OwnedProcessTree::stillAlive($driver), 'Actual owned Amp tree must exit before cleanup: '.json_encode($driver));
            }
            $this->assertFalse($process->isRunning());
        } finally {
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testRefuseExistingEndpointDoesNotUnlinkForeignSocketOnShutdown(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-2');
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
            $supervisor->shutdownUntil(microtime(true) + 5.0);
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
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-env');

        $previousEnv = $_ENV;
        $_ENV = [];
        try {
            $this->assertNotFalse(getenv('HOME'));
            // Empty $_ENV must not strip HOME from the broker child.
            $supervisor->start($paths);
            $this->assertTrue($this->brokerProcess($supervisor)->isRunning());
        } finally {
            $_ENV = $previousEnv;
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testControllerOwnerEofStopsBrokerHelperAndAllowsDurableDbRestart(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $first = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-owner-eof');

        $restart = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        try {
            $first->start($paths);
            $process = $this->brokerProcess($first);
            $pid = $process->getPid();
            $this->assertNotNull($pid);
            $driverTree = OwnedProcessTree::descendants($pid);
            $driverPids = array_column($driverTree, 'pid');
            $this->assertActualAmpWorker($driverTree);
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
            foreach ($driverTree as $driver) {
                $this->assertFalse(OwnedProcessTree::stillAlive($driver), 'Actual owned Amp tree must exit before cleanup: '.json_encode($driver));
            }

            $restartPaths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-owner-eof');
            $this->assertSame($paths->databasePath, $restartPaths->databasePath);
            $restart->start($restartPaths);
            $this->assertTrue($this->brokerProcess($restart)->isRunning(), 'durable queue DB must restart after owner EOF released lifetime locks');
        } finally {
            $restart->shutdownUntil(microtime(true) + 5.0);
            $first->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testShutdownUntilHonorsSharedDeadlineEscalation(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-shared-deadline');

        $supervisor->start($paths);
        $process = $this->brokerProcess($supervisor);
        $this->assertTrue($process->isRunning());

        $started = microtime(true);
        $supervisor->shutdownUntil($started);
        $elapsed = microtime(true) - $started;

        $this->assertFalse($process->isRunning());
        $this->assertLessThan(1.0, $elapsed, 'shared deadline already expired must escalate without waiting full grace');
        $this->assertFalse($this->brokerProcessAlive($supervisor));
    }

    public function testClientSendRenewAndAckWithoutSecondClaim(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $supervisor = new SessionSqliteQueueBrokerSupervisor($logger, $config, readyTimeoutSeconds: 15.0);
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-client');

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
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testConfiguredPayloadRoundTripsOversizedExecuteLlmStepThroughAmpWorker(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $maxPayload = $this->configuredMaxPayload();
        $supervisor = new SessionSqliteQueueBrokerSupervisor(
            $logger,
            $config,
            readyTimeoutSeconds: 15.0,
            maxPayload: $maxPayload,
        );
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-payload');
        $serializer = new PhpSerializer();
        // Exceed both the UI bootstrap transfer budget and the original 1 MiB broker default.
        $bodyText = str_repeat('x', 8_388_608 + Limits::MAX_PAYLOAD + 64);
        $envelope = new Envelope(new ExecuteLlmStep(
            'run-payload',
            1,
            'step-payload',
            1,
            'idem-payload',
            'tools-ref',
            [new AgentMessage('user', [['type' => 'text', 'text' => $bodyText]])],
        ));
        $encoded = $serializer->encode($envelope);
        $this->assertIsString($encoded['body']);
        $headers = \array_key_exists('headers', $encoded) && \is_array($encoded['headers'])
            ? json_encode($encoded['headers'], \JSON_THROW_ON_ERROR)
            : '{}';
        $encodedBytes = \strlen($encoded['body']) + \strlen($headers);
        $this->assertGreaterThan(8_388_608 + Limits::MAX_PAYLOAD, $encodedBytes);
        $this->assertLessThanOrEqual($maxPayload, $encodedBytes);

        $transport = null;
        try {
            $supervisor->start($paths);
            $this->assertSame($maxPayload, $supervisor->advertisedMaxPayload());
            $connect = static fn (): Client => Client::connect($paths->endpointPath);
            $transport = new Transport(
                new BrokerConnection($connect),
                new QueueName('llm'),
                $serializer,
                new BrokerConnection($connect),
                $paths->endpointPath,
            );
            $transport->send($envelope);
            $received = iterator_to_array($transport->get());
            $this->assertCount(1, $received);
            $message = $received[0]->getMessage();
            $this->assertInstanceOf(ExecuteLlmStep::class, $message);
            $this->assertSame($bodyText, $message->messages[0]->content[0]['text'] ?? null);
            $transport->ack($received[0]);
        } finally {
            $transport?->close();
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testConfiguredConnectionBudgetAdmitsTopologyAndRejectsOverflow(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $required = SessionSqliteQueueBrokerTopology::requiredConnections(4, 32);
        $supervisor = new SessionSqliteQueueBrokerSupervisor(
            $logger,
            $config,
            readyTimeoutSeconds: 15.0,
            maxPayload: $this->configuredMaxPayload(),
        );
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-capacity');
        /** @var list<Client> $clients */
        $clients = [];
        try {
            $supervisor->start($paths, $required);
            $this->assertSame($required, $supervisor->advertisedMaxConnections());
            for ($i = 0; $i < $required; ++$i) {
                $clients[] = Client::connect($paths->endpointPath);
            }
            $this->assertCount($required, $clients);
            $this->expectException(TransportException::class);
            Client::connect($paths->endpointPath);
        } finally {
            foreach ($clients as $client) {
                $client->close();
            }
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    public function testReadyEventBelowTopologyRequirementFailsClosed(): void
    {
        $logger = new TestLogger();
        $config = $this->runtimeConfig();
        $maxPayload = $this->configuredMaxPayload();
        $supervisor = new SessionSqliteQueueBrokerSupervisor(
            $logger,
            $config,
            readyTimeoutSeconds: 15.0,
            maxPayload: $maxPayload,
        );
        $paths = SessionSqliteQueuePaths::forSession($this->tmpDir.'/.hatfield/sessions', 'broker-mismatch');

        try {
            $supervisor->start($paths, 64);
            $this->assertSame(64, $supervisor->advertisedMaxConnections());

            $required = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'requiredMaxConnectionsForReady');
            $required->setValue($supervisor, 65);
            $ready = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'ready');
            $ready->setValue($supervisor, false);
            $carry = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'stdoutCarry');
            $carry->setValue($supervisor, json_encode([
                'event' => 'ready',
                'max_payload' => $maxPayload,
                'max_connections' => 64,
            ], \JSON_THROW_ON_ERROR)."\n");

            $consume = new \ReflectionMethod(SessionSqliteQueueBrokerSupervisor::class, 'consumeReadyEvents');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('advertised max_connections=64 below required 65');
            $consume->invoke($supervisor);
        } finally {
            $supervisor->shutdownUntil(microtime(true) + 5.0);
        }
    }

    private function runtimeConfig(): RuntimeProcessConfig
    {
        $locator = $this->createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn(\dirname(__DIR__, 4).'/bin/console');
        $locator->method('command')->willReturn([\PHP_BINARY, \dirname(__DIR__, 4).'/bin/console']);

        return new RuntimeProcessConfig($locator, $this->tmpDir);
    }

    private function configuredMaxPayload(): int
    {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        try {
            $value = $kernel->getContainer()->getParameter('hatfield.sqlite_queue.max_payload');
            $this->assertIsInt($value);
            $this->assertSame(1_000_000_000, $value);

            return $value;
        } finally {
            $kernel->shutdown();
        }
    }

    private function brokerProcessAlive(SessionSqliteQueueBrokerSupervisor $supervisor): bool
    {
        $property = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'process');
        $process = $property->getValue($supervisor);

        return $process instanceof \Symfony\Component\Process\Process && $process->isRunning();
    }

    private function brokerProcess(SessionSqliteQueueBrokerSupervisor $supervisor): \Symfony\Component\Process\Process
    {
        $property = new \ReflectionProperty(SessionSqliteQueueBrokerSupervisor::class, 'process');
        $process = $property->getValue($supervisor);
        $this->assertInstanceOf(\Symfony\Component\Process\Process::class, $process);

        return $process;
    }

    private function processCommandLineContains(int $pid, string $needle): bool
    {
        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (false === $cmdline) {
            return false;
        }

        return str_contains(str_replace("\0", ' ', $cmdline), $needle);
    }

    /** @param list<array{pid:int, start:string, cmdline:string, exe:string}> $tree */
    private function assertActualAmpWorker(array $tree): void
    {
        $workers = array_filter($tree, static fn (array $row): bool => 'amp-process' === trim($row['cmdline']));
        $this->assertNotSame([], $workers, 'Actual Amp runner with rewritten process title must exist, not only its shell wrapper');
        foreach ($workers as $worker) {
            $this->assertSame(realpath(\PHP_BINARY), $worker['exe'], 'Actual source worker must execute the selected PHP interpreter');
        }
    }
}
