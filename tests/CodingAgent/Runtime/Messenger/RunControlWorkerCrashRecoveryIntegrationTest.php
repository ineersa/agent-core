<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlClaimRecovery;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlWorkerOwnership;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Revolt\EventLoop;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Process\Process;

/**
 * Thesis: a real supervised run_control worker that claims AdvanceRun and
 * fatals under a hard memory limit is observed DEAD; reclaim succeeds only
 * while exclusive ownership is held for the SQL update; the replacement worker
 * executes the real AdvanceRunHandler stack and advances the canonical run at
 * most once.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\RunControlClaimRecovery
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\RunControlWorkerOwnership
 * @covers \Ineersa\CodingAgent\Runtime\Controller\ConsumerSupervisor
 */
final class RunControlWorkerCrashRecoveryIntegrationTest extends IsolatedKernelTestCase
{
    private const int REDELIVER_TIMEOUT_SECONDS = 315360000;

    private string $workerDatabaseDir = '';
    private string $oomMarker = '';
    private string $holderScriptDir = '';
    private string $launcherDir = '';

    /** @var list<Process> */
    private array $ownedProcesses = [];

    /** @var list<\Doctrine\DBAL\Connection> */
    private array $ownedConnections = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->workerDatabaseDir = TestDirectoryIsolation::createProjectTempDir('run-control-recovery-db', 0o750);
        $this->oomMarker = $this->workerDatabaseDir.'/oom-once.marker';
        $lockDir = $this->isolatedCwd().'/.hatfield/tmp/controller-locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0o750, true) && !is_dir($lockDir)) {
            $this->fail('Unable to create session owner lock directory: '.$lockDir);
        }
    }

    protected function tearDown(): void
    {
        if ('' !== $this->workerDatabaseDir) {
            @file_put_contents($this->workerDatabaseDir.'/owner-release.marker', '1');
        }
        $survivors = [];
        foreach (array_reverse($this->ownedProcesses) as $process) {
            $exit = $this->waitForProcessExit($process, 2.0);
            if (null === $exit && $process->isRunning()) {
                if ($this->isSessionTaggedWorker($process)) {
                    $survivors[] = $process->getCommandLine().' pid='.var_export($process->getPid(), true)
                        ."\nstdout=".$process->getOutput()."\nstderr=".$process->getErrorOutput();
                    continue;
                }
                $process->stop(0);
            }
        }
        $this->ownedProcesses = [];
        if ([] !== $survivors) {
            $this->fail("HATFIELD_SESSION_ID-tagged workers must self-exit; left alive:\n".implode("\n---\n", $survivors));
        }

        foreach ($this->ownedConnections as $connection) {
            $connection->close();
        }
        $this->ownedConnections = [];

        if ('' !== $this->workerDatabaseDir) {
            TestDirectoryIsolation::removeDirectory($this->workerDatabaseDir);
            $this->workerDatabaseDir = '';
        }
        if ('' !== $this->holderScriptDir) {
            TestDirectoryIsolation::removeDirectory($this->holderScriptDir);
            $this->holderScriptDir = '';
        }
        if ('' !== $this->launcherDir) {
            TestDirectoryIsolation::removeDirectory($this->launcherDir);
            $this->launcherDir = '';
        }

        parent::tearDown();
    }

    public function testSupervisedMemoryFatalReclaimsAndReplacementAdvancesCanonicalRunOnce(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        $relativeDb = '../tmp/'.basename($this->workerDatabaseDir);
        $this->migrateWorkerDatabases($sessionId, $relativeDb);
        $this->seedCanonicalRunReadyForAdvance($sessionId);

        $transport = $this->openTransport($queueName, $relativeDb);
        $advanceStepId = 'advance-after-crash-'.$sessionId;
        $transport->send(new Envelope(new AdvanceRun(
            runId: $sessionId,
            turnNo: 0,
            stepId: $advanceStepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $sessionId.'|'.$advanceStepId),
        )));

        $launcher = $this->createWorkerLauncherScript();
        $locator = $this->createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn($launcher);
        $locator->method('command')->willReturn([\PHP_BINARY, $launcher]);
        $config = new RuntimeProcessConfig($locator, $this->isolatedCwd());

        $fresh = $this->freshTransportConnection($relativeDb);
        $recovery = $this->createRecovery($fresh);

        $previousEnv = $this->pushWorkerEnv($sessionId, $relativeDb, withOomMarker: true);
        try {
            $supervisor = new ConsumerSupervisor(
                new TestLogger(),
                $config,
                runControlClaimRecovery: $recovery,
                sessionId: $sessionId,
            );

            $supervisor->launch('run_control', 0);
            $first = $this->getConsumerProcess($supervisor, 'run_control#0');
            $this->ownedProcesses[] = $first;
            $firstExit = $this->waitForProcessExit($first, 8.0);
            $diag = 'exit='.var_export($firstExit, true)."\nstdout=".$first->getOutput()."\nstderr=".$first->getErrorOutput();
            $this->assertNotNull($firstExit, 'First worker must exit after intentional OOM. '.$diag);
            $this->assertFileExists($this->oomMarker, 'OOM marker missing; worker never entered recovery middleware. '.$diag);
            $this->assertNotSame(0, $firstExit, 'Memory fatal must exit non-zero. '.$diag);

            $supervisor->supervise();
            $second = $this->waitForReplacementConsumer($supervisor, $first, 8.0);
            $this->ownedProcesses[] = $second;
            $this->assertNotSame($first->getPid(), $second->getPid());
            $secondExit = $this->waitForProcessExit($second, 8.0);
            $this->assertSame(0, $secondExit, "Replacement worker must ack the reclaimed AdvanceRun.\n".$second->getErrorOutput());

            $types = $this->readEventTypes($sessionId);
            $this->assertContains(RunEventTypeEnum::RunStarted->value, $types);
            $this->assertContains(RunEventTypeEnum::TurnAdvanced->value, $types);
            $this->assertContains(RunEventTypeEnum::HistoryPositionSet->value, $types);
            $this->assertSame(
                1,
                \count(array_filter($types, static fn (string $type): bool => RunEventTypeEnum::TurnAdvanced->value === $type)),
                'Replacement must advance the run at most once.',
            );
            $this->assertSame(
                0,
                (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$queueName],
                ),
                'Acked AdvanceRun must leave no abandoned claim.',
            );
        } finally {
            $this->restoreEnv($previousEnv);
        }
    }

    public function testLiveOwnershipBlocksReclaimAcrossIndependentProcessBarrier(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        $relativeDb = '../tmp/'.basename($this->workerDatabaseDir);
        $this->migrateWorkerDatabases($sessionId, $relativeDb);

        $transport = $this->openTransport($queueName, $relativeDb);
        $transport->send(new Envelope(new AdvanceRun(
            runId: $sessionId,
            turnNo: 0,
            stepId: 'blocked-advance',
            attempt: 1,
            idempotencyKey: hash('sha256', $sessionId.'|blocked-advance'),
        )));
        $this->assertCount(1, iterator_to_array($transport->get()));

        $holder = $this->startOwnershipHolder($sessionId);
        $this->ownedProcesses[] = $holder;
        $this->assertTrue($this->waitForFile($this->workerDatabaseDir.'/owner-held.marker', 3.0), $holder->getErrorOutput());

        $fresh = $this->freshTransportConnection($relativeDb);
        $result = $this->createRecovery($fresh)->releaseAbandonedClaims($sessionId);
        $this->assertSame('live_owner_present', $result['failure']);
        $this->assertSame(0, $result['released']);
        $this->assertSame(
            1,
            (int) $fresh->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                [$queueName],
            ),
        );

        $this->assertNotFalse(file_put_contents($this->workerDatabaseDir.'/owner-release.marker', '1'));
        $this->assertNotNull($this->waitForProcessExit($holder, 3.0));
        $this->assertFalse($holder->isRunning());
    }

    public function testRecoveryHoldsExclusiveLockThroughoutSqlUpdate(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        $otherQueue = 'llm_'.$sessionId;
        $relativeDb = '../tmp/'.basename($this->workerDatabaseDir);
        $this->migrateWorkerDatabases($sessionId, $relativeDb);

        $runControl = $this->openTransport($queueName, $relativeDb);
        $llm = $this->openTransport($otherQueue, $relativeDb);
        $runControl->send(new Envelope(new AdvanceRun(
            runId: $sessionId,
            turnNo: 0,
            stepId: 'contention-advance',
            attempt: 1,
            idempotencyKey: hash('sha256', $sessionId.'|contention-advance'),
        )));
        $llm->send(new Envelope(new \stdClass()));
        $this->assertCount(1, iterator_to_array($runControl->get()));
        $this->assertCount(1, iterator_to_array($llm->get()));

        $fresh = $this->freshTransportConnection($relativeDb);
        $lockDir = $this->isolatedCwd().'/.hatfield/tmp/controller-locks';
        $probe = new class {
            public bool $heldDuringUpdate = false;
            public int $otherClaimed = 0;
        };
        $connection = $this->getMockBuilder(\Doctrine\DBAL\Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['executeStatement'])
            ->getMock();
        $connection->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use ($fresh, $sessionId, $lockDir, $otherQueue, $probe): int {
                $ownership = new RunControlWorkerOwnership(
                    new LockFactory(new FlockStore($lockDir)),
                    $this->isolatedCwd(),
                    new TestLogger(),
                );
                $result = $ownership->tryAcquire($sessionId);
                $probe->heldDuringUpdate = !$result['acquired'] && 'ownership_held' === $result['failure'];
                $probe->otherClaimed = (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$otherQueue],
                );

                return $fresh->executeStatement($sql, $params);
            });
        $recovery = new RunControlClaimRecovery(
            $connection,
            new RunControlWorkerOwnership(
                new LockFactory(new FlockStore($lockDir)),
                $this->isolatedCwd(),
                new TestLogger(),
            ),
            new TestLogger(),
        );

        $result = $recovery->releaseAbandonedClaims($sessionId);
        $this->assertNull($result['failure']);
        $this->assertSame(1, $result['released']);
        $this->assertTrue($probe->heldDuringUpdate, 'Recovery lock must remain held during the UPDATE.');
        $this->assertSame(1, $probe->otherClaimed, 'Other queues must stay claimed while run_control recovers.');
        $this->assertSame(
            1,
            (int) $fresh->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                [$otherQueue],
            ),
        );
        $this->assertCount(1, iterator_to_array($runControl->get()));
    }

    public function testProductionWorkerStartupRecoversWithdrawnSharedStateBeforeAdvance(): void
    {
        $sessionId = 'session-'.bin2hex(random_bytes(3));
        $queueName = 'run_control_'.$sessionId;
        $relativeDb = '../tmp/'.basename($this->workerDatabaseDir);
        $this->migrateWorkerDatabases($sessionId, $relativeDb);
        $this->seedCanonicalRunReadyForAdvance($sessionId);

        $transport = $this->openTransport($queueName, $relativeDb);
        $advanceStepId = 'advance-after-withdraw-'.$sessionId;
        $transport->send(new Envelope(new AdvanceRun(
            runId: $sessionId,
            turnNo: 0,
            stepId: $advanceStepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $sessionId.'|'.$advanceStepId),
        )));

        $previousEnv = $this->pushWorkerEnv($sessionId, $relativeDb, withOomMarker: false);
        try {
            $this->runProjectionSetup('withdraw', $sessionId);
        } finally {
            $this->restoreEnv($previousEnv);
        }

        $launcher = $this->createWorkerLauncherScript();
        $locator = $this->createStub(AppExecutableLocator::class);
        $locator->method('path')->willReturn($launcher);
        $locator->method('command')->willReturn([\PHP_BINARY, $launcher]);
        $config = new RuntimeProcessConfig($locator, $this->isolatedCwd());
        $fresh = $this->freshTransportConnection($relativeDb);
        $recovery = $this->createRecovery($fresh);

        $previousEnv = $this->pushWorkerEnv($sessionId, $relativeDb, withOomMarker: false);
        try {
            $supervisor = new ConsumerSupervisor(
                new TestLogger(),
                $config,
                runControlClaimRecovery: $recovery,
                sessionId: $sessionId,
            );
            $supervisor->launch('run_control', 0);
            $worker = $this->getConsumerProcess($supervisor, 'run_control#0');
            $this->ownedProcesses[] = $worker;
            $exit = $this->waitForProcessExit($worker, 8.0);
            $this->assertSame(0, $exit, "Worker must recover withdrawn state and ack AdvanceRun.\n".$worker->getErrorOutput());

            $types = $this->readEventTypes($sessionId);
            $this->assertContains(RunEventTypeEnum::TurnAdvanced->value, $types);
            $this->runProjectionSetup('assert-ready', $sessionId);
            $this->assertSame(
                0,
                (int) $fresh->fetchOne(
                    'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                    [$queueName],
                ),
            );
        } finally {
            $this->restoreEnv($previousEnv);
        }
    }

    private function seedCanonicalRunReadyForAdvance(string $sessionId): void
    {
        /** @var EventStoreInterface $eventStore */
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $eventStore->append(new RunEvent(
            runId: $sessionId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-step',
                'payload' => [
                    'messages' => [],
                    'metadata' => ['model' => 'test-model'],
                ],
            ],
            createdAt: new \DateTimeImmutable('2026-09-30T00:00:01+00:00'),
        ));

        $types = $this->readEventTypes($sessionId);
        $this->assertContains(RunEventTypeEnum::RunStarted->value, $types);
    }

    private function createWorkerLauncherScript(): string
    {
        $this->launcherDir = TestDirectoryIsolation::createProjectTempDir('run-control-recovery-launcher', 0o750);
        $script = $this->launcherDir.'/launcher.php';
        $worker = ProjectDir::get().'/tests/CodingAgent/Runtime/Messenger/Support/RunControlRecoveryKernelWorker.php';
        file_put_contents($script, <<<PHP
<?php
declare(strict_types=1);
require {$this->export($worker)};
PHP);

        return $script;
    }

    private function startOwnershipHolder(string $sessionId): Process
    {
        $this->holderScriptDir = TestDirectoryIsolation::createProjectTempDir('run-control-owner-hold', 0o750);
        $script = $this->holderScriptDir.'/hold.php';
        $marker = $this->workerDatabaseDir.'/owner-held.marker';
        $release = $this->workerDatabaseDir.'/owner-release.marker';
        $lockDir = $this->isolatedCwd().'/.hatfield/tmp/controller-locks';
        file_put_contents($script, <<<PHP
<?php
declare(strict_types=1);
require {$this->export(ProjectDir::get().'/vendor/autoload.php')};
use Ineersa\CodingAgent\Runtime\Messenger\RunControlWorkerOwnership;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
\$cwd = getenv('HATFIELD_CWD') ?: '';
\$sessionId = \$argv[1] ?? '';
\$ownership = new RunControlWorkerOwnership(new LockFactory(new FlockStore({$this->export($lockDir)})), \$cwd, new NullLogger());
\$result = \$ownership->tryAcquire(\$sessionId);
if (!\$result['acquired']) {
    fwrite(STDERR, 'failed to acquire: '.(\$result['failure'] ?? 'unknown').PHP_EOL);
    exit(2);
}
file_put_contents({$this->export($marker)}, (string) getmypid());
\$deadline = microtime(true) + 8.0;
while (microtime(true) < \$deadline) {
    if (is_file({$this->export($release)})) {
        break;
    }
    usleep(5_000);
}
\$ownership->release();
exit(is_file({$this->export($release)}) ? 0 : 3);
PHP);

        // Pass session via argv so this helper is not HATFIELD_SESSION_ID-tagged.
        $holder = new Process([\PHP_BINARY, $script, $sessionId], $this->isolatedCwd(), [
            'HATFIELD_CWD' => $this->isolatedCwd(),
        ]);
        $holder->start();

        return $holder;
    }

    private function createRecovery(\Doctrine\DBAL\Connection $connection): RunControlClaimRecovery
    {
        $lockDir = $this->isolatedCwd().'/.hatfield/tmp/controller-locks';

        return new RunControlClaimRecovery(
            $connection,
            new RunControlWorkerOwnership(
                new LockFactory(new FlockStore($lockDir)),
                $this->isolatedCwd(),
                new TestLogger(),
            ),
            new TestLogger(),
        );
    }

    private function openTransport(string $queueName, string $relativeDb): DoctrineTransport
    {
        $fresh = $this->freshTransportConnection($relativeDb);
        $configuration = DoctrineMessengerConnection::buildConfiguration(
            \sprintf(
                'doctrine://messenger_transport?queue_name=%s&redeliver_timeout=%d',
                $queueName,
                self::REDELIVER_TIMEOUT_SECONDS,
            ),
        );

        return new DoctrineTransport(
            new DoctrineMessengerConnection($configuration, $fresh),
            new PhpSerializer(),
        );
    }

    private function freshTransportConnection(string $relativeDb): \Doctrine\DBAL\Connection
    {
        $path = ProjectDir::get().'/var/test/'.$relativeDb.'/messenger-transport.sqlite';
        $this->assertFileExists($path);

        /** @var \Doctrine\Bundle\DoctrineBundle\ConnectionFactory $factory */
        $factory = self::getContainer()->get('doctrine.dbal.connection_factory');
        $connection = $factory->createConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        $this->ownedConnections[] = $connection;

        return $connection;
    }

    /**
     * @return list<string>
     */
    private function readEventTypes(string $sessionId): array
    {
        $path = $this->isolatedCwd().'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
        $this->assertFileExists($path);
        $types = [];
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertIsString($decoded['type'] ?? null);
            $types[] = $decoded['type'];
        }

        return $types;
    }

    /**
     * @return array<string, string|false>
     */
    private function pushWorkerEnv(string $sessionId, string $relativeDb, bool $withOomMarker): array
    {
        $keys = array_keys($this->workerEnv($sessionId, $relativeDb, $withOomMarker));
        $previous = [];
        foreach ($keys as $key) {
            $previous[$key] = getenv($key);
        }
        foreach ($this->workerEnv($sessionId, $relativeDb, $withOomMarker) as $key => $value) {
            $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }

        return $previous;
    }

    /**
     * @param array<string, string|false> $previous
     */
    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv($key.'='.$value);
            }
        }
    }

    /** @return array<string, string> */
    private function workerEnv(string $sessionId, string $relativeDb, bool $withOomMarker): array
    {
        $env = [
            'APP_ENV' => 'test',
            'APP_DEBUG' => '0',
            'HATFIELD_CWD' => $this->isolatedCwd(),
            'HATFIELD_SESSION_ID' => $sessionId,
            'HATFIELD_TEST_DATABASE_PATH' => $relativeDb.'/state.sqlite',
            'HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH' => $relativeDb.'/messenger-transport.sqlite',
            'HATFIELD_RUN_CONTROL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=run_control_{$sessionId}&redeliver_timeout=315360000",
            'HATFIELD_LLM_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=llm_{$sessionId}&redeliver_timeout=315360000",
            'HATFIELD_TOOL_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=tool_{$sessionId}&redeliver_timeout=315360000",
            'HATFIELD_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=agent_{$sessionId}&redeliver_timeout=315360000",
            'HATFIELD_MCP_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=mcp_{$sessionId}&redeliver_timeout=315360000",
            'HATFIELD_EXTENSION_AGENT_TRANSPORT_DSN' => "doctrine://messenger_transport?queue_name=extension_agent_{$sessionId}&redeliver_timeout=315360000",
        ];
        if ($withOomMarker) {
            $env['HATFIELD_TEST_RUN_CONTROL_OOM_ONCE_PATH'] = $this->oomMarker;
        }

        return $env;
    }

    private function migrateWorkerDatabases(string $sessionId, string $relativeDb): void
    {
        $env = $this->workerEnv($sessionId, $relativeDb, withOomMarker: false);
        foreach ([
            [\PHP_BINARY, ProjectDir::get().'/bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration'],
            [
                \PHP_BINARY,
                ProjectDir::get().'/bin/console',
                'doctrine:migrations:migrate',
                '--em=messenger_transport',
                '--configuration=config/migrations/messenger_transport.yaml',
                '--no-interaction',
                '--allow-no-migration',
            ],
        ] as $command) {
            $process = new Process($command, ProjectDir::get(), $env);
            $process->mustRun();
        }
    }

    private function waitForReplacementConsumer(ConsumerSupervisor $supervisor, Process $first, float $timeoutSeconds): Process
    {
        $found = null;
        $watchId = EventLoop::repeat(0.01, static function () use ($supervisor, $first, &$found, &$watchId): void {
            $supervisor->supervise();
            $consumers = (new \ReflectionProperty(ConsumerSupervisor::class, 'consumers'))->getValue($supervisor);
            if (\is_array($consumers) && isset($consumers['run_control#0']) && $consumers['run_control#0'] instanceof Process) {
                $candidate = $consumers['run_control#0'];
                if ($candidate->isRunning() && $candidate->getPid() !== $first->getPid()) {
                    $found = $candidate;
                    EventLoop::cancel($watchId);
                    EventLoop::getDriver()->stop();
                }
            }
        });
        $capId = EventLoop::delay($timeoutSeconds, static function () use (&$watchId): void {
            EventLoop::cancel($watchId);
            EventLoop::getDriver()->stop();
        });

        EventLoop::run();
        EventLoop::cancel($capId);

        if ($found instanceof Process) {
            return $found;
        }

        $this->fail('Replacement run_control consumer did not launch before safety cap.');
    }

    private function waitForProcessExit(Process $process, float $timeoutSeconds): ?int
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (!$process->isRunning()) {
                return $process->getExitCode();
            }
            usleep(5_000);
        }

        return null;
    }

    private function waitForFile(string $path, float $timeoutSeconds): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return true;
            }
            usleep(5_000);
        }

        return false;
    }

    private function getConsumerProcess(ConsumerSupervisor $supervisor, string $key): Process
    {
        $consumers = (new \ReflectionProperty(ConsumerSupervisor::class, 'consumers'))->getValue($supervisor);
        $this->assertIsArray($consumers);
        $this->assertArrayHasKey($key, $consumers);

        return $consumers[$key];
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    private function runProjectionSetup(string $mode, string $sessionId): void
    {
        $script = ProjectDir::get().'/tests/CodingAgent/Runtime/Messenger/Support/RunControlRecoveryProjectionSetup.php';
        $process = new Process([\PHP_BINARY, $script, $mode, $sessionId], $this->isolatedCwd(), $_ENV);
        $process->mustRun();
        $this->assertStringContainsString('"ok":true', $process->getOutput(), $process->getErrorOutput());
    }

    private function isSessionTaggedWorker(Process $process): bool
    {
        $command = $process->getCommandLine();

        return str_contains($command, 'launcher.php')
            || str_contains($command, 'RunControlRecoveryKernelWorker.php');
    }
}
