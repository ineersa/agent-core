<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlClaimRecovery;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlWorkerOwnership;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Revolt\EventLoop;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Thesis: reclaim clears the session run_control claim only when exclusive worker
 * ownership is free. A live ownership lock blocks reclaim; other queues stay claimed.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\RunControlClaimRecovery
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor
 */
final class RunControlClaimRecoveryTest extends IsolatedKernelTestCase
{
    private const int REDELIVER_TIMEOUT_SECONDS = 315360000;

    private string $lockDir = '';

    /** @var list<string> */
    private array $ownedTempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = TestDirectoryIsolation::createProjectTempDir('run-control-claim-locks', 0o750);
        $this->ownedTempDirs = [];
    }

    protected function tearDown(): void
    {
        foreach (EventLoop::getIdentifiers() as $id) {
            EventLoop::cancel($id);
        }
        if ('' !== $this->lockDir) {
            TestDirectoryIsolation::removeDirectory($this->lockDir);
            $this->lockDir = '';
        }
        foreach (array_reverse($this->ownedTempDirs) as $dir) {
            TestDirectoryIsolation::removeDirectory($dir);
        }
        $this->ownedTempDirs = [];
        parent::tearDown();
    }

    public function testSupervisedRunControlCrashReleasesAdvanceRunWhenOwnershipFree(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        [$transport, $fresh] = $this->openStockTransport($queueName);
        $argvDir = $this->createOwnedTempDir('run-control-claim-argv');
        $argvFile = $argvDir.'/argv.json';

        try {
            $advance = new AdvanceRun(
                runId: $sessionId,
                turnNo: 2,
                stepId: 'advance-after-tools',
                attempt: 1,
                idempotencyKey: hash('sha256', $sessionId.'|advance|2'),
            );
            $transport->send(new Envelope($advance));
            $claimed = iterator_to_array($transport->get());
            $this->assertCount(1, $claimed);
            $this->assertInstanceOf(AdvanceRun::class, $claimed[0]->getMessage());
            $this->assertSame([], iterator_to_array($transport->get()), 'Claimed AdvanceRun must stay unreceivable under the long lease.');

            $recovery = $this->createRecovery($fresh, $this->isolatedCwd());
            $supervisor = $this->createSupervisor($argvFile, exitCode: 255, sessionId: $sessionId, recovery: $recovery);
            $supervisor->launch('run_control', 0);
            $deadWorker = $this->getConsumerProcess($supervisor, 'run_control#0');
            $deadWorker->wait();

            $supervisor->supervise();

            $replacement = iterator_to_array($transport->get());
            $this->assertCount(1, $replacement, 'Replacement worker must receive the same AdvanceRun after reclaim.');
            $message = $replacement[0]->getMessage();
            $this->assertInstanceOf(AdvanceRun::class, $message);
            $this->assertSame($sessionId, $message->runId());
            $this->assertSame(2, $message->turnNo());
            $this->assertSame('advance-after-tools', $message->stepId());
            $transport->ack($replacement[0]);
        } finally {
            $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
            $fresh->close();
        }
    }

    public function testGracefulRunControlRecycleAlsoReleasesAdvanceRunClaim(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        [$transport, $fresh] = $this->openStockTransport($queueName);
        $argvDir = $this->createOwnedTempDir('run-control-recycle-argv');
        $argvFile = $argvDir.'/argv.json';

        try {
            $transport->send(new Envelope(new AdvanceRun(
                runId: $sessionId,
                turnNo: 1,
                stepId: 'advance-recycle',
                attempt: 1,
                idempotencyKey: hash('sha256', $sessionId.'|advance|1'),
            )));
            $this->assertCount(1, iterator_to_array($transport->get()));
            $this->assertSame([], iterator_to_array($transport->get()));

            $recovery = $this->createRecovery($fresh, $this->isolatedCwd());
            $supervisor = $this->createSupervisor($argvFile, exitCode: 0, sessionId: $sessionId, recovery: $recovery);
            $supervisor->launch('run_control', 0);
            $this->getConsumerProcess($supervisor, 'run_control#0')->wait();
            $supervisor->supervise();

            $reclaimed = iterator_to_array($transport->get());
            $this->assertCount(1, $reclaimed);
            $this->assertInstanceOf(AdvanceRun::class, $reclaimed[0]->getMessage());
            $transport->ack($reclaimed[0]);
            $this->assertArrayHasKey('run_control#0', $this->consumerKeysRunning($supervisor));
        } finally {
            $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
            $fresh->close();
        }
    }

    public function testLiveWorkerOwnershipBlocksReclaimAndSurfacesFailure(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        [$transport, $fresh] = $this->openStockTransport($queueName);
        $cwd = $this->isolatedCwd();

        $holder = $this->createOwnership($cwd);
        $acquired = $holder->tryAcquire($sessionId);
        $this->assertTrue($acquired['acquired']);

        $argvDir = $this->createOwnedTempDir('run-control-live-owner-argv');
        $argvFile = $argvDir.'/argv.json';
        $failures = [];

        try {
            $transport->send(new Envelope(new AdvanceRun($sessionId, 1, 'advance-live', 1, 'key-live')));
            $this->assertCount(1, iterator_to_array($transport->get()));

            $recovery = $this->createRecovery($fresh, $cwd);
            $supervisor = $this->createSupervisor($argvFile, exitCode: 255, sessionId: $sessionId, recovery: $recovery);
            $supervisor->onRunControlClaimRecoveryFailed(static function (string $sid, int $exit, string $stderr, string $failure) use (&$failures): void {
                $failures[] = [$sid, $exit, $failure];
            });
            $supervisor->launch('run_control', 0);
            $this->getConsumerProcess($supervisor, 'run_control#0')->wait();
            $supervisor->supervise();

            $this->assertSame([[$sessionId, 255, 'live_owner_present']], $failures);
            $this->assertSame([], iterator_to_array($transport->get()), 'Claim must remain held while live ownership exists.');
            $this->assertSame(
                1,
                (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$queueName],
                ),
            );
            $this->assertSame([], $this->consumerKeysRunning($supervisor), 'Unsafe relaunch must be blocked after reclaim failure.');
        } finally {
            $holder->release();
            $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
            $fresh->close();
        }
    }

    public function testReleaseAbandonedClaimsDoesNotTouchOtherQueues(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $runControlQueue = 'run_control_'.$sessionId;
        $llmQueue = 'llm_'.$sessionId;
        [$runControlTransport, $fresh] = $this->openStockTransport($runControlQueue);
        $llmTransport = $this->transportForQueue($fresh, $llmQueue);

        try {
            $runControlTransport->send(new Envelope(new AdvanceRun($sessionId, 1, 'advance-a', 1, 'key-a')));
            $llmTransport->send(new Envelope(new \stdClass()));
            $this->assertCount(1, iterator_to_array($runControlTransport->get()));
            $this->assertCount(1, iterator_to_array($llmTransport->get()));

            $recovery = $this->createRecovery($fresh, $this->isolatedCwd());
            $result = $recovery->releaseAbandonedClaims($sessionId);
            $this->assertNull($result['failure']);
            $this->assertSame(1, $result['released']);

            $this->assertCount(1, iterator_to_array($runControlTransport->get()));
            $this->assertSame([], iterator_to_array($llmTransport->get()), 'Claimed llm rows must remain untouched.');
            $this->assertSame(
                1,
                (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$llmQueue],
                ),
            );
        } finally {
            $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name IN (?, ?)', [$runControlQueue, $llmQueue]);
            $fresh->close();
        }
    }

    public function testMissingSessionIdFailsClosed(): void
    {
        $recovery = $this->createRecovery(
            self::getContainer()->get('doctrine.dbal.messenger_transport_connection'),
            $this->isolatedCwd(),
        );

        $this->assertSame(
            ['released' => 0, 'failure' => 'missing_session_id'],
            $recovery->releaseAbandonedClaims('unknown'),
        );
        $this->assertSame(
            ['released' => 0, 'failure' => 'missing_session_id'],
            $recovery->releaseAbandonedClaims('   '),
        );
    }

    public function testSameProcessLiveOwnerBlocksReclaim(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        [$transport, $fresh] = $this->openStockTransport($queueName);
        $cwd = $this->isolatedCwd();
        $ownership = $this->createOwnership($cwd);

        try {
            $transport->send(new Envelope(new AdvanceRun($sessionId, 1, 'advance-same', 1, 'key-same')));
            $this->assertCount(1, iterator_to_array($transport->get()));

            $acquired = $ownership->tryAcquire($sessionId);
            $this->assertTrue($acquired['acquired']);

            $recovery = new RunControlClaimRecovery($fresh, $ownership, new TestLogger());
            $result = $recovery->releaseAbandonedClaims($sessionId);
            $this->assertSame('live_owner_present', $result['failure']);
            $this->assertSame(0, $result['released']);
            $this->assertSame(
                1,
                (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$queueName],
                ),
            );
        } finally {
            $ownership->release();
            $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
            $fresh->close();
        }
    }

    /**
     * @return array{0: DoctrineTransport, 1: \Doctrine\DBAL\Connection}
     */
    private function openStockTransport(string $queueName): array
    {
        $kernelTransport = self::getContainer()->get('doctrine.dbal.messenger_transport_connection');
        $params = $kernelTransport->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path);

        /** @var \Doctrine\Bundle\DoctrineBundle\ConnectionFactory $factory */
        $factory = self::getContainer()->get('doctrine.dbal.connection_factory');
        $fresh = $factory->createConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        $this->assertTrue($fresh->createSchemaManager()->tablesExist(['messenger_messages']));

        return [$this->transportForQueue($fresh, $queueName), $fresh];
    }

    private function transportForQueue(\Doctrine\DBAL\Connection $connection, string $queueName): DoctrineTransport
    {
        $configuration = DoctrineMessengerConnection::buildConfiguration(
            \sprintf(
                'doctrine://messenger_transport?queue_name=%s&redeliver_timeout=%d',
                $queueName,
                self::REDELIVER_TIMEOUT_SECONDS,
            ),
        );

        return new DoctrineTransport(
            new DoctrineMessengerConnection($configuration, $connection),
            new PhpSerializer(),
        );
    }

    private function createRecovery(\Doctrine\DBAL\Connection $connection, string $cwd): RunControlClaimRecovery
    {
        return new RunControlClaimRecovery(
            $connection,
            $this->createOwnership($cwd),
            new TestLogger(),
        );
    }

    private function createOwnership(string $cwd): RunControlWorkerOwnership
    {
        return new RunControlWorkerOwnership(
            new LockFactory(new FlockStore($this->lockDir)),
            $cwd,
            new TestLogger(),
        );
    }

    private function createSupervisor(
        string $argvCaptureFile,
        int $exitCode,
        string $sessionId,
        RunControlClaimRecovery $recovery,
    ): ConsumerSupervisor {
        $locator = $this->createStub(AppExecutableLocator::class);
        $script = $this->createArgvCaptureScript($argvCaptureFile, $exitCode);
        $locator->method('path')->willReturn($script);
        $locator->method('command')->willReturn(['php', $script]);
        $config = new RuntimeProcessConfig($locator, $this->createOwnedTempDir('run-control-supervisor-cwd'));

        return new ConsumerSupervisor(
            new TestLogger(),
            $config,
            runControlClaimRecovery: $recovery,
            sessionId: $sessionId,
        );
    }

    private function createOwnedTempDir(string $prefix): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir($prefix, 0o750);
        $this->ownedTempDirs[] = $dir;

        return $dir;
    }

    private function createArgvCaptureScript(string $argvCaptureFile, int $exitCode): string
    {
        $scriptDir = $this->createOwnedTempDir('run-control-launcher');
        $script = $scriptDir.'/launcher.php';
        file_put_contents($script, \sprintf(
            "<?php\nfile_put_contents(%s, json_encode(\$argv, JSON_THROW_ON_ERROR));\nexit(%d);\n",
            var_export($argvCaptureFile, true),
            $exitCode,
        ));

        return $script;
    }

    /**
     * @return array<string, bool>
     */
    private function consumerKeysRunning(ConsumerSupervisor $supervisor): array
    {
        $consumers = (new \ReflectionProperty(ConsumerSupervisor::class, 'consumers'))->getValue($supervisor);
        $this->assertIsArray($consumers);
        $running = [];
        foreach ($consumers as $key => $process) {
            $running[$key] = $process->isRunning();
        }

        return $running;
    }

    private function getConsumerProcess(ConsumerSupervisor $supervisor, string $key): \Symfony\Component\Process\Process
    {
        $consumers = (new \ReflectionProperty(ConsumerSupervisor::class, 'consumers'))->getValue($supervisor);
        $this->assertIsArray($consumers);
        $this->assertArrayHasKey($key, $consumers);

        return $consumers[$key];
    }
}
