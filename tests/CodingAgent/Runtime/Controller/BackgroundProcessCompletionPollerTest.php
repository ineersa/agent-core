<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Ineersa\AgentCore\Application\Pipeline\AgentRunner;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Ineersa\SqliteQueue\Protocol\Frame;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

use function Amp\async;
use function Amp\Socket\listen;

/**
 * Integration tests for BackgroundProcessCompletionPoller.
 *
 * All key deps (ProcessStore, BackgroundProcessManager) are final and
 * cannot be mocked. We use real instances with a real DB and filesystem.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
#[CoversClass(BackgroundProcessCompletionPoller::class)]
final class BackgroundProcessCompletionPollerTest extends IsolatedKernelTestCase
{
    /** @var array<array{string, UserCommand}> */
    private array $sentCommands = [];

    private AgentSessionClient $clientSpy;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentCommands = [];

        $this->clientSpy = new class($this->sentCommands) implements AgentSessionClient {
            /** @param array<array{string, UserCommand}> $sentCommands */
            public function __construct(
                private array &$sentCommands,
            ) {
            }

            public function start(\Ineersa\CodingAgent\Runtime\Contract\StartRunRequest $request): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected in test');
            }

            public function acknowledgeBootstrap(array $cut): void
            {
            }

            public function cancelBootstrap(string $runId): void
            {
            }

            public function attach(string $runId): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected in test');
            }

            public function send(string $runId, UserCommand $command): void
            {
                $this->sentCommands[] = [$runId, $command];
            }

            public function beginObservingChildRun(string $childRunId): void
            {
            }

            public function endObservingChildRun(string $childRunId): void
            {
            }

            public function events(string $runId, int $afterSeq = 0): iterable
            {
                return [];
            }

            /**
             * No-op shutdown: this spy owns no runtime to tear down.
             */
            public function shutdown(): void
            {
            }

            public function refreshMcpCatalog(string $runId): void
            {
            }

            public function cancel(string $runId): void
            {
            }

            public function shellExecute(string $command, string $sessionId, string $cwd): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected in test');
            }

            public function compact(string $runId, ?string $customInstructions = null): void
            {
            }

            public function repair(string $runId, bool $apply = true): \Ineersa\CodingAgent\Runtime\Contract\RepairResult
            {
                return new \Ineersa\CodingAgent\Runtime\Contract\RepairResult(false, false, 'No repairable corruption detected.');
            }
        };

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_poller_test');
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);

        parent::tearDown();
    }

    public function testPollSendsFollowUpAndMarksNotified(): void
    {
        $logPath = $this->tmpDir.'/test-poller.log';
        file_put_contents($logPath, "Hello world\n");
        $now = new \DateTimeImmutable();

        $this->persistProcess(
            pid: 10001,
            logPath: $logPath,
            command: 'sleep 60 && echo "Hello world"',
            backgroundedAt: $now->modify('-60 seconds'),
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
        );

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-poller-run', $runId);
        $this->assertSame('append_message', $command->type);
        $this->assertStringContainsString('[BG_PROCESS_DONE]', $command->text ?? '');
        $this->assertStringContainsString('PID 10001', $command->text ?? '');
        $this->assertStringContainsString('Hello world', $command->text ?? '');

        $entity = $this->findProcess(10001);
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->completionNotifiedAt);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCompletionPollDoesNotOverlapPendingPublish(bool $rejectFirst): void
    {
        $now = new \DateTimeImmutable();
        $log = $this->tmpDir.'/completion.log';
        file_put_contents($log, 'completed');
        $process = $this->persistProcess(10002, $log, 'echo completed', $now, $now, 0, BackgroundProcessStatusEnum::Finished);
        $dir = TestDirectoryIsolation::createOsTempDir('sq');
        $server = listen('unix://'.$dir.'/s');
        $transport = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($dir.'/s'), [], new PhpSerializer());
        $locator = new SerializedBrokerSendersLocator(new SendersLocator(['*' => ['queue']], new ServiceLocator(['queue' => static fn () => $transport])));
        $runner = new AgentRunner(new MessageBus([new SendMessageMiddleware($locator)]), self::getContainer()->get('serializer'));
        $this->clientSpy = $this->createStub(AgentSessionClient::class);
        $this->clientSpy->method('send')->willReturnCallback(static function (string $runId, UserCommand $command) use ($runner): void {
            self::assertSame('append_message', $command->type);
            $runner->appendMessage($runId, new AgentMessage('user', [['type' => 'text', 'text' => $command->text]]));
        });
        $store = self::getContainer()->get(ProcessStore::class);
        $config = $this->createBgConfig();
        $poller = $this->createPoller($store, new BackgroundProcessManager($store, new ProcessLifecycle($config, new NullLogger()), $config, new NullLogger()));
        $poll = new \ReflectionMethod($poller, 'poll');
        $pending = new DeferredFuture();
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $requests = [];
        $cancel = new TimeoutCancellation(2);
        $peer = async(static function () use ($server, $pending, $release, $rejectFirst, &$requests, $cancel): void {
            $socket = $server->accept($cancel);
            try {
                $hello = Frame::read($socket, $cancel);
                $socket->write((new Frame(['v' => 1, 'id' => $hello->control['id'], 'ok' => true, 'result' => ['max_payload' => 1040000, 'max_connections' => 64]]))->encode());
                while (null !== ($request = Frame::read($socket, $cancel))) {
                    $requests[] = $request;
                    if (1 === \count($requests)) {
                        $pending->complete();
                        $release->getFuture()->await($cancel);
                    }
                    $result = $rejectFirst && 1 === \count($requests)
                        ? ['ok' => false, 'error' => ['code' => 'invalid_queue_name']]
                        : ['ok' => true, 'result' => \count($requests)];
                    $socket->write((new Frame(['v' => 1, 'id' => $request->control['id']] + $result))->encode());
                }
            } finally {
                $socket->close();
            }
        });
        try {
            $first = async(static fn () => $poll->invoke($poller));
            $pending->getFuture()->await($cancel);
            $overlap = async(static function () use ($entered, $poll, $poller): void {
                $entered->complete();
                $poll->invoke($poller);
            });
            $entered->getFuture()->await($cancel);
            $skippedWhilePending = $overlap->isComplete();
            $this->assertFalse($first->isComplete());
            $this->assertNull($process->completionNotifiedAt);
            $release->complete();
            $first->await($cancel);
            $overlap->await($cancel);
            $this->assertTrue($skippedWhilePending, 'Another pass must return instead of queueing a duplicate publish.');
            $this->assertCount(1, $requests);
            $this->assertStringContainsString('[BG_PROCESS_DONE]', $requests[0]->body);
            if ($rejectFirst) {
                $this->assertNull($process->completionNotifiedAt);
            } else {
                $this->assertNotNull($process->completionNotifiedAt);
                $process = $this->persistProcess(10003, $log, 'echo completed again', $now, $now, 0, BackgroundProcessStatusEnum::Finished);
            }
            // Success and rejection must both release the pass guard.
            $poll->invoke($poller);
            $this->assertNotNull($process->completionNotifiedAt);
            $this->assertCount(2, $requests);
            $poll->invoke($poller);
            $this->assertCount(2, $requests, 'Already notified completions must remain suppressed.');
        } finally {
            if (!$release->isComplete()) {
                $release->complete();
            }
            $transport->close();
            $server->close();
            $peer->await($cancel);
            // Finish the owned loop, not just its suspended main-Fiber await,
            // before following tests exercise synchronous stdout shutdown.
            EventLoop::run();
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testPollSkipsNonBackgrounded(): void
    {
        $logPath = $this->tmpDir.'/test-foreground.log';
        file_put_contents($logPath, "foreground output\n");
        $now = new \DateTimeImmutable();

        $this->persistProcess(
            pid: 20001,
            logPath: $logPath,
            command: 'echo "foreground"',
            backgroundedAt: null, // NOT backgrounded
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
        );

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertEmpty($this->sentCommands);

        // Verify NOT marked notified
        $entity = $this->findProcess(20001);
        $this->assertNotNull($entity);
        $this->assertNull($entity->completionNotifiedAt);
    }

    public function testPollSkipsAlreadyNotified(): void
    {
        $logPath = $this->tmpDir.'/test-already-notified.log';
        file_put_contents($logPath, "already notified output\n");
        $now = new \DateTimeImmutable();

        $proc = $this->persistProcess(
            pid: 30001,
            logPath: $logPath,
            command: 'echo "already notified"',
            backgroundedAt: $now->modify('-60 seconds'),
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
        );
        $proc->completionNotifiedAt = $now;
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->flush();

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertEmpty($this->sentCommands);
    }

    public function testLogNotFoundStillSendsNotification(): void
    {
        $now = new \DateTimeImmutable();

        // No log file created — readLogTail will fail
        $this->persistProcess(
            pid: 40001,
            logPath: $this->tmpDir.'/nonexistent.log',
            command: 'echo "test"',
            backgroundedAt: $now->modify('-60 seconds'),
            finishedAt: $now,
            exitCode: 1,
            status: BackgroundProcessStatusEnum::Finished,
        );

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [, $command] = $this->sentCommands[0];
        $this->assertStringContainsString('log file not found', $command->text ?? '');
        $this->assertStringContainsString('exit 1', $command->text ?? '');

        $entity = $this->findProcess(40001);
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->completionNotifiedAt);
    }

    public function testEmptyStringSessionIdNormalizedToNull(): void
    {
        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        // Construct with empty string — must be normalized to null
        $poller = new BackgroundProcessCompletionPoller(
            processStore: $store,
            processManager: $manager,
            sessionClient: $this->clientSpy,
            emitter: new RuntimeEventEmitter(
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $this->createStub(LoggerInterface::class),
            artifactRegistry: self::getContainer()->get(AgentArtifactRegistry::class),
            sessionId: '',
        );

        $ref = new \ReflectionProperty($poller, 'sessionId');
        $this->assertNull($ref->getValue($poller));
    }

    public function testRegressionFreshBackgroundedProcessWithoutFinishedAt(): void
    {
        // Regression for the bug where a backgrounded process finishes
        // naturally (writes status file, exits) but the DB entity still
        // has finishedAt=NULL because no code calls resolveEntityStatus().
        // The poller must refresh selected unfinished processes before
        // deciding which candidates need completion notifications.
        $logPath = $this->tmpDir.'/test-regression.log';
        file_put_contents($logPath, "regression output line\n");

        $pid = 50001;
        $statusPath = $this->tmpDir.'/'.$pid.'.status';
        file_put_contents($statusPath, '0'); // exit code 0

        $now = new \DateTimeImmutable();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Persist a process with finishedAt=NULL and status=Running —
        // exactly what the DB looks like after a backgrounded process
        // has finished without any explicit list()/find() call.
        $proc = new BackgroundProcess();
        $proc->pid = $pid;
        $proc->sessionId = 'test-regression-run';
        $proc->command = 'echo "regression output"';
        $proc->logPath = $logPath;
        $proc->statusPath = $statusPath;
        $proc->backgroundedAt = $now->modify('-60 seconds');
        $proc->finishedAt = null;
        $proc->exitCode = null;
        $proc->status = BackgroundProcessStatusEnum::Running;
        $proc->startedAt = $now->modify('-60 seconds');
        $em->persist($proc);
        $em->flush();

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager, 'test-regression-run');
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        // Assert append_message was sent with [BG_PROCESS_DONE] prefix
        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-regression-run', $runId);
        $this->assertSame('append_message', $command->type);
        $this->assertStringContainsString('[BG_PROCESS_DONE]', $command->text ?? '');
        $this->assertStringContainsString('PID '.$pid, $command->text ?? '');
        $this->assertStringContainsString('regression output', $command->text ?? '');

        // Assert DB state was updated by the refresh
        $entity = $this->findProcess($pid);
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->finishedAt, 'finishedAt must be populated after refresh');
        $this->assertSame(BackgroundProcessStatusEnum::Finished, $entity->status);
        $this->assertNotNull($entity->completionNotifiedAt, 'completionNotifiedAt must be set after notification');
    }

    public function testPollNotifiesChildSessionBackgroundCompletion(): void
    {
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create(
            parentRunId: 'test-poller-run',
            artifactId: 'agent_child_bg',
            agentRunId: 'child-run-bg',
            agentName: 'scout',
            kind: AgentArtifactKindEnum::Subagent,
        );

        $logPath = $this->tmpDir.'/child-bg.log';
        file_put_contents($logPath, "child background output\n");
        $now = new \DateTimeImmutable();

        $this->persistProcess(
            pid: 60001,
            logPath: $logPath,
            command: 'sleep 1',
            backgroundedAt: $now->modify('-30 seconds'),
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
            sessionId: 'child-run-bg',
        );

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('child-run-bg', $runId);
        $this->assertSame('append_message', $command->type);
        $this->assertStringContainsString('[BG_PROCESS_DONE]', $command->text ?? '');
    }

    public function testPollIgnoresForeignSessionWhenControllerScoped(): void
    {
        $now = new \DateTimeImmutable();
        $foreignLog = $this->tmpDir.'/foreign-bg.log';
        file_put_contents($foreignLog, "foreign output\n");
        $ownedLog = $this->tmpDir.'/owned-bg.log';
        file_put_contents($ownedLog, "owned output\n");

        $this->persistProcess(
            pid: 70001,
            logPath: $foreignLog,
            command: 'sleep 1',
            backgroundedAt: $now->modify('-30 seconds'),
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
            sessionId: 'foreign-other-session',
        );

        $this->persistProcess(
            pid: 70002,
            logPath: $ownedLog,
            command: 'sleep 2',
            backgroundedAt: $now->modify('-30 seconds'),
            finishedAt: $now,
            exitCode: 0,
            status: BackgroundProcessStatusEnum::Finished,
            sessionId: 'test-poller-run',
        );

        $store = self::getContainer()->get(ProcessStore::class);
        $bgConfig = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger());
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager, 'test-poller-run');
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-poller-run', $runId);
        $this->assertStringContainsString('PID 70002', $command->text ?? '');
        $this->assertStringNotContainsString('PID 70001', $command->text ?? '');
    }

    #[TestWith([0])]
    #[TestWith([100])]
    public function testEmptyOwnedTreeUsesOneCandidateSelect(int $children): void
    {
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        for ($index = 0; $index < $children; ++$index) {
            $registry->create('test-poller-run', 'historical-'.$index, 'child-'.$index, 'test', AgentArtifactKindEnum::Fork);
        }
        $this->persistProcess(900001, $this->tmpDir.'/foreign.log', 'foreign', new \DateTimeImmutable(), new \DateTimeImmutable(), 0, BackgroundProcessStatusEnum::Finished, 'foreign');
        $logger = self::getContainer()->get('test.sql_logger');
        $this->assertInstanceOf(TestLogger::class, $logger);
        $logger->records = [];

        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class));
        (new \ReflectionMethod($poller, 'poll'))->invoke($poller);

        $selects = array_filter($logger->records, static fn (array $record): bool => str_starts_with($record['context']['sql'] ?? '', 'SELECT'));
        $this->assertCount(1, $selects, 'Empty historical children must not add process SELECTs.');
        $this->assertStringContainsString('background_process', array_values($selects)[0]['context']['sql']);
        $this->assertSame([], $this->sentCommands);
    }

    #[TestWith([0, false])]
    #[TestWith([1, false])]
    #[TestWith([100, false])]
    #[TestWith([3, true])]
    public function testCandidateRefreshFlushesOnceBeforeSeparateDeliverySettlements(int $count, bool $complete): void
    {
        $this->persistRefreshCandidates($count, $complete);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $counter = new class {
            public int $flushes = 0;
            public int $changeDetectionPasses = 0;

            public function preFlush(): void
            {
                ++$this->flushes;
            }

            public function onFlush(): void
            {
                ++$this->changeDetectionPasses;
            }
        };
        $events = $em->getEventManager();
        $events->addEventListener([Events::preFlush, Events::onFlush], $counter);
        $published = 0;
        $this->clientSpy = $this->createStub(AgentSessionClient::class);
        $this->clientSpy->method('send')->willReturnCallback(function () use ($em, &$published): void {
            $db = $em->getConnection();
            $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM background_process WHERE finished_at IS NULL'));
            $this->assertSame($published, (int) $db->fetchOne('SELECT COUNT(*) FROM background_process WHERE completion_notified_at IS NOT NULL'), 'Each previous delivery must already be durable before the next send.');
            ++$published;
        });
        $logger = self::getContainer()->get('test.sql_logger');
        $this->assertInstanceOf(TestLogger::class, $logger);
        $logger->records = [];
        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class));
        try {
            (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
        } finally {
            $events->removeEventListener([Events::preFlush, Events::onFlush], $counter);
        }

        $expectedDeliveries = $complete ? $count : 0;
        $expectedFlushes = (0 === $count ? 0 : 1) + $expectedDeliveries;
        $this->assertSame($expectedFlushes, $counter->flushes);
        $this->assertSame($expectedFlushes, $counter->changeDetectionPasses);
        $this->assertSame($expectedDeliveries, $published);
        $queries = array_filter($logger->records, static fn (array $record): bool => str_contains($record['context']['sql'] ?? '', 'session_id IN'));
        $this->assertCount(1, $queries);
        $this->assertSame($expectedDeliveries, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM background_process WHERE completion_notified_at IS NOT NULL'));
        $this->assertSame($complete ? 0 : $count, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM background_process WHERE finished_at IS NULL'));
    }

    public function testRefreshFailureSkipsOnlyThatRecordAndRetriesNextPass(): void
    {
        [$failed, $completed] = $this->persistRefreshCandidates(2, true);
        $originalClock = Clock::get();
        $clock = $this->createStub(ClockInterface::class);
        $calls = 0;
        $clock->method('now')->willReturnCallback(static function () use (&$calls): \DateTimeImmutable {
            if (0 === $calls++) {
                throw new \RuntimeException('isolated status resolution failure');
            }

            return new \DateTimeImmutable();
        });
        $logger = new TestLogger();
        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class), logger: $logger);
        $poll = new \ReflectionMethod($poller, 'poll');
        Clock::set($clock);
        try {
            $poll->invoke($poller);
        } finally {
            Clock::set($originalClock);
        }

        $this->assertCount(1, $this->sentCommands);
        $this->assertStringContainsString('PID '.$completed->pid, $this->sentCommands[0][1]->text ?? '');
        $this->assertNull($failed->finishedAt);
        $this->assertNull($failed->completionNotifiedAt);
        $this->assertNotNull($completed->completionNotifiedAt);
        $errors = array_filter($logger->records, static fn (array $record): bool => 'bg_process_completion.refresh_failed' === $record['message']);
        $this->assertCount(1, $errors);
        $poll->invoke($poller);
        $this->assertCount(2, $this->sentCommands);
        $this->assertNotNull($failed->completionNotifiedAt);
    }

    public function testRefreshFlushFailureDefersEveryDeliveryUntilRetry(): void
    {
        $processes = $this->persistRefreshCandidates(3, true);
        $events = self::getContainer()->get(EntityManagerInterface::class)->getEventManager();
        $listener = new class {
            public function preFlush(): void
            {
                throw new \RuntimeException('refresh flush failed');
            }
        };
        $logger = new TestLogger();
        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class), logger: $logger);
        $poll = new \ReflectionMethod($poller, 'poll');
        $events->addEventListener([Events::preFlush], $listener);
        try {
            $poll->invoke($poller);
        } finally {
            $events->removeEventListener([Events::preFlush], $listener);
        }
        $this->assertSame([], $this->sentCommands);
        $errors = array_filter($logger->records, static fn (array $record): bool => 'bg_process_completion.refresh_flush_failed' === $record['message']);
        $this->assertCount(1, $errors);
        $poll->invoke($poller);
        $this->assertCount(3, $this->sentCommands);
        foreach ($processes as $process) {
            $this->assertNotNull($process->completionNotifiedAt);
        }
    }

    public function testCandidateQueryRefreshesStaleManagedStateFromDatabase(): void
    {
        $now = new \DateTimeImmutable();
        $logPath = $this->tmpDir.'/fresh.log';
        file_put_contents($logPath, 'fresh database completion');
        $process = $this->persistProcess(900002, $logPath, 'fresh', null, $now, 7, BackgroundProcessStatusEnum::Finished);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        // Another worker can accept the process without updating this controller's identity map.
        $em->getConnection()->update('background_process', ['backgrounded_at' => $now->format('Y-m-d H:i:s')], ['id' => $process->id]);
        $this->assertNull($process->backgroundedAt);

        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class));
        $poll = new \ReflectionMethod($poller, 'poll');
        $poll->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        $this->assertNotNull($process->backgroundedAt);
        $this->assertNotNull($process->completionNotifiedAt);
        $this->assertStringContainsString('exit 7', $this->sentCommands[0][1]->text ?? '');
        $poll->invoke($poller);
        $this->assertCount(1, $this->sentCommands, 'Persisted notified rows must not be selected again.');
    }

    public function testArtifactOwnershipSurvivesProjectionResetAndDiscoversIndependentChild(): void
    {
        $registry = self::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create('test-poller-run', 'retained-artifact', 'retained-child', 'test', AgentArtifactKindEnum::Fork);
        $repository = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $repository->replace(new RunState('test-poller-run', RunStatus::Completed));
        $repository->replace(new RunState('retained-child', RunStatus::Completed, parentRunId: 'test-poller-run'));
        $repository->replace(new RunState('foreign', RunStatus::Completed));
        (new RunOperationalProjectionControllerSessionLifecycleListener($repository))->onSessionStarting(new ControllerSessionStartingEvent('test-poller-run'));
        $this->assertNull($repository->findOperationalStatus('test-poller-run'));
        $this->assertNull($repository->findOperationalStatus('retained-child'));
        $this->assertNotNull($repository->findOperationalStatus('foreign'));

        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class));
        $poll = new \ReflectionMethod($poller, 'poll');
        $poll->invoke($poller);
        $this->assertSame([], $this->sentCommands);
        // This independently registered child has never had an operational projection.
        $registry->create('test-poller-run', 'independent-artifact', 'independent-child', 'test', AgentArtifactKindEnum::Subagent);
        foreach (['test-poller-run', 'retained-child', 'independent-child', 'foreign'] as $index => $runId) {
            $logPath = $this->tmpDir.'/ownership-'.$index.'.log';
            file_put_contents($logPath, 'owned completion '.$index);
            $this->persistProcess(900010 + $index, $logPath, 'ownership', new \DateTimeImmutable(), new \DateTimeImmutable(), 0, BackgroundProcessStatusEnum::Finished, $runId);
        }
        $logger = self::getContainer()->get('test.sql_logger');
        $this->assertInstanceOf(TestLogger::class, $logger);
        $logger->records = [];

        $poll->invoke($poller);

        $this->assertSame(['test-poller-run', 'retained-child', 'independent-child'], array_column($this->sentCommands, 0));
        $this->assertNull($this->findProcess(900013)?->completionNotifiedAt, 'Foreign completions must remain untouched.');
        $this->assertNotNull($this->findProcess(900012)?->completionNotifiedAt);
        $candidateQueries = array_filter($logger->records, static fn (array $record): bool => str_contains($record['context']['sql'] ?? '', 'session_id IN'));
        $this->assertCount(1, $candidateQueries, 'All owned completions share one candidate SELECT.');
    }

    public function testCandidateOrderingPreservesScopePrecedenceForDuplicatePid(): void
    {
        self::getContainer()->get(AgentArtifactRegistry::class)->create('test-poller-run', 'child-artifact', 'child-run', 'test', AgentArtifactKindEnum::Fork);
        $childLog = $this->tmpDir.'/child-identity.log';
        $parentLog = $this->tmpDir.'/parent-identity.log';
        file_put_contents($childLog, 'child immutable record output');
        file_put_contents($parentLog, 'parent immutable record output');
        $now = new \DateTimeImmutable();
        // Insert child first so global ID order differs from the former root/child query order.
        $child = $this->persistProcess(900020, $childLog, 'child record', $now, $now, 3, BackgroundProcessStatusEnum::Finished, 'child-run');
        $parent = $this->persistProcess(900020, $parentLog, 'parent record', $now, $now, 4, BackgroundProcessStatusEnum::Finished);
        $poller = $this->createPoller(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(BackgroundProcessManager::class));

        (new \ReflectionMethod($poller, 'poll'))->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        $this->assertSame('child-run', $this->sentCommands[0][0]);
        $this->assertStringContainsString('child immutable record output', $this->sentCommands[0][1]->text ?? '');
        $this->assertStringNotContainsString('parent immutable record output', $this->sentCommands[0][1]->text ?? '');
        $this->assertNotNull($child->completionNotifiedAt);
        $this->assertNull($parent->completionNotifiedAt);
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    /**
     * Persist a BackgroundProcess entity via EntityManager.
     */
    private function persistProcess(
        int $pid,
        string $logPath,
        string $command,
        ?\DateTimeImmutable $backgroundedAt,
        \DateTimeImmutable $finishedAt,
        ?int $exitCode,
        BackgroundProcessStatusEnum $status,
        string $sessionId = 'test-poller-run',
    ): BackgroundProcess {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $proc = new BackgroundProcess();
        $proc->pid = $pid;
        $proc->sessionId = $sessionId;
        $proc->command = $command;
        $proc->logPath = $logPath;
        $proc->statusPath = $this->tmpDir.'/'.$pid.'.status';
        $proc->backgroundedAt = $backgroundedAt;
        $proc->finishedAt = $finishedAt;
        $proc->exitCode = $exitCode;
        $proc->status = $status;
        $proc->startedAt = $finishedAt->modify('-60 seconds');

        $em->persist($proc);
        $em->flush();

        return $proc;
    }

    /** @return list<BackgroundProcess> */
    private function persistRefreshCandidates(int $count, bool $complete): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $processes = [];
        for ($index = 0; $index < $count; ++$index) {
            $process = new BackgroundProcess();
            // Active fixtures simulate multiple records using this live PHP PID;
            // these tests never launch or signal a workload.
            $process->pid = $complete ? 99000000 + $index : getmypid();
            $process->sessionId = 'test-poller-run';
            $process->command = 'refresh fixture';
            $process->logPath = $this->tmpDir.'/refresh-'.$index.'.log';
            $process->statusPath = $this->tmpDir.'/refresh-'.$index.'.status';
            $process->startedAt = new \DateTimeImmutable();
            $process->backgroundedAt = new \DateTimeImmutable();
            if ($complete) {
                file_put_contents($process->logPath, 'completed fixture output');
                file_put_contents($process->statusPath, '0');
            }
            $em->persist($process);
            $processes[] = $process;
        }
        $em->flush();

        return $processes;
    }

    private function findProcess(int $pid): ?BackgroundProcess
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(BackgroundProcess::class)->findOneBy(['pid' => $pid]);
    }

    private function createBgConfig(): BackgroundProcessConfig
    {
        return new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
    }

    private function createPoller(ProcessStore $store, BackgroundProcessManager $manager, ?string $controllerSessionId = 'test-poller-run', ?LoggerInterface $logger = null): BackgroundProcessCompletionPoller
    {
        return new BackgroundProcessCompletionPoller(
            processStore: $store,
            processManager: $manager,
            sessionClient: $this->clientSpy,
            emitter: new RuntimeEventEmitter(
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $logger ?? $this->createStub(LoggerInterface::class),
            artifactRegistry: self::getContainer()->get(AgentArtifactRegistry::class),
            sessionId: $controllerSessionId,
        );
    }

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir((string) $item);
            } else {
                @unlink((string) $item);
            }
        }

        @rmdir($path);
    }
}
