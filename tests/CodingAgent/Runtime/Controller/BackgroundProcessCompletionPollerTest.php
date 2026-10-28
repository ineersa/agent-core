<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Query;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Pipeline\BackgroundCompletionHandler;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BgStatusArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;
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
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
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
    /** @var array<array{string, DeliverBackgroundCompletionDTO}> */
    private array $sentCommands = [];

    private MessageBusInterface $commandBus;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentCommands = [];

        $this->commandBus = $this->createStub(MessageBusInterface::class);
        $this->commandBus->method('dispatch')->willReturnCallback(function (DeliverBackgroundCompletionDTO $message): Envelope {
            $this->sentCommands[] = [$message->runId(), $message];
            // This poller-level double models a successful owner admission.
            // The production admission/receipt boundary has its own handler tests.
            if (null !== $message->processRecordId) {
                self::getContainer()->get(ProcessStore::class)->markCompletionNotified($message->processRecordId, $message->runId());
            }

            return new Envelope($message);
        });
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_poller_test');
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);

        parent::tearDown();
    }

    #[TestWith(['stop'])]
    #[TestWith(['natural'])]
    #[TestWith(['failure'])]
    #[TestWith(['already-finished'])]
    public function testNativeStopAcknowledgmentDoesNotDuplicateCompletion(string $mode): void
    {
        $store = self::getContainer()->get(ProcessStore::class);
        $config = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));
        $poller = null;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$poller): void {
            if ('background_process.stopped' === $message) {
                // Poll after native exit but before stop() returns. Completion
                // must already be quiet, not deferred until the final stop flush.
                (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
            }
        });
        $manager = new BackgroundProcessManager($store, $lifecycle, $config, $logger);
        $poller = $this->createPoller($store, $manager, 'native-stop');
        $fifo = $this->tmpDir.'/release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $release = fopen($fifo, 'r+b');
        $this->assertIsResource($release);
        try {
            $exit = 'failure' === $mode ? 7 : 0;
            $process = $manager->start('printf ready; read -r release < '.escapeshellarg($fifo).'; printf done; exit '.$exit, 'native-stop', 'call');
            $manager->markBackgroundedForRecord($process->id, 'native-stop');
            $record = $store->fetchByRecordId($process->id);
            $this->assertNotNull($record);
            $deadline = hrtime(true) + 2_000_000_000;
            while (!str_contains((string) file_get_contents($record->logPath), 'ready') && hrtime(true) < $deadline) {
                $this->assertTrue($lifecycle->isAlive($process->pid));
                usleep(10_000);
            }
            $this->assertStringContainsString('ready', (string) file_get_contents($record->logPath));
            if ('stop' !== $mode) {
                fwrite($release, "release\n");
                $deadline = hrtime(true) + 2_000_000_000;
                while (null === $lifecycle->readStatusFile($record->statusPath) && hrtime(true) < $deadline) {
                    usleep(10_000);
                }
                $this->assertSame($exit, $lifecycle->readStatusFile($record->statusPath));
            }
            if (\in_array($mode, ['stop', 'already-finished'], true)) {
                $accessor = new StackToolExecutionContextAccessor();
                $tool = new BgStatusTool($manager, $config, $accessor);
                $context = new ToolContext('native-stop', 0, 'stop-call', 'bg_status', new NullCancellationToken(), 30);
                $result = $accessor->with($context, static fn () => $tool(new BgStatusArgumentsDTO(action: 'stop', pid: $process->pid)));
                $this->assertStringContainsString('stop' === $mode ? 'stopped.' : 'had already finished.', $result);
            }
            $poller = $this->createPoller($store, $manager, 'native-stop');
            (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
            (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
            $this->assertCount('stop' === $mode ? 0 : 1, $this->sentCommands);
            $this->assertNotNull($store->snapshot($process->id)?->completionNotifiedAt);
            $this->assertNotNull($manager->findByRecordId($process->id)?->finishedAt);
            $this->assertStringContainsString('ready', $manager->readLogTail('native-stop', $process->pid)->content);
            if ('stop' !== $mode) {
                $this->assertStringContainsString('exit '.$exit, $this->sentCommands[0][1]->text ?? '');
            }
        } finally {
            fclose($release);
            $manager->shutdownCleanup();
        }
    }

    public function testStaleCandidateCannotPublishAfterIndependentExplicitStop(): void
    {
        $store = self::getContainer()->get(ProcessStore::class);
        $config = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));
        $owner = new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('stale Bash completion');
        PreparedEventStoreSeeder::append(self::getContainer()->get(PreparedTransitionEventStoreInterface::class), RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => []]]));
        try {
            $process = $owner->start('sleep 60', $runId, 'call');
            $owner->markBackgroundedForRecord($process->id, $runId);
            $candidate = new DeliverBackgroundCompletionDTO($runId, 'background-process-'.$process->id, 'stale completion', processRecordId: $process->id);
            $owner->stop($process->pid, $runId);
            $this->assertNotNull($store->snapshot($process->id)?->completionNotifiedAt);
            self::getContainer()->get(BackgroundCompletionHandler::class)($candidate);
            $this->assertNull(self::getContainer()->get(BackgroundCompletionReceiptRepository::class)->receipt($candidate->identity));
        } finally {
            $owner->shutdownCleanup();
        }
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-poller-run', $runId);
        $this->assertInstanceOf(DeliverBackgroundCompletionDTO::class, $command);
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
        $this->commandBus = new MessageBus([new SendMessageMiddleware($locator)]);
        $store = self::getContainer()->get(ProcessStore::class);
        $config = $this->createBgConfig();
        $poller = $this->createPoller($store, new BackgroundProcessManager($store, new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore())), $config, new NullLogger()));
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
            $this->assertNull($store->snapshot($process->id)?->completionNotifiedAt, 'Enqueue is not durable owner admission.');
            // Success and rejection must both release the pass guard. Until
            // owner admission acknowledges delivery, another pass may retry.
            $poll->invoke($poller);
            $this->assertCount(2, $requests);
            $store->markCompletionNotified($process->id, $process->sessionId);
            $poll->invoke($poller);
            $this->assertCount(2, $requests, 'Owner-acknowledged completions remain suppressed.');
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        // Construct with empty string — must be normalized to null
        $poller = new BackgroundProcessCompletionPoller(
            processStore: $store,
            processManager: $manager,
            commandBus: $this->commandBus,
            emitter: new RuntimeEventEmitter(
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $this->createStub(LoggerInterface::class),
            artifactRegistry: self::getContainer()->get(AgentArtifactRegistry::class),
            receipts: self::getContainer()->get(BackgroundCompletionReceiptRepository::class),
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager, 'test-regression-run');
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        // Assert append_message was sent with [BG_PROCESS_DONE] prefix
        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-regression-run', $runId);
        $this->assertInstanceOf(DeliverBackgroundCompletionDTO::class, $command);
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
        $manager = new BackgroundProcessManager($store, $lifecycle, $bgConfig, new NullLogger());

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('child-run-bg', $runId);
        $this->assertInstanceOf(DeliverBackgroundCompletionDTO::class, $command);
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
        $lifecycle = new ProcessLifecycle($bgConfig, new NullLogger(), new LockFactory(new FlockStore()));
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

    #[TestWith(['list'])]
    #[TestWith(['log'])]
    public function testIndependentReaderPreservesTimeoutCauseBeforeOwnerPoll(string $read): void
    {
        $store = self::getContainer()->get(ProcessStore::class);
        $config = $this->createBgConfig();
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));
        $owner = new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
        $fifo = $this->tmpDir.'/timeout-cleanup';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $release = fopen($fifo, 'r+b');
        $this->assertIsResource($release);
        $otherKernel = new Kernel('test', true);
        $otherKernel->boot();
        try {
            $otherContainer = $otherKernel->getContainer()->get('test.service_container');
            $this->assertNotSame(self::getContainer()->get(EntityManagerInterface::class), $otherContainer->get(EntityManagerInterface::class));
            $otherStore = $otherContainer->get(ProcessStore::class);
            $reader = new BackgroundProcessManager($otherStore, $lifecycle, $config, new NullLogger());
            // Group TERM and wrapper forwarding can both reach this child. Do not
            // re-enter cleanup and consume the one-shot FIFO release a second time.
            $cleanup = 'trap "" TERM; IFS= read -r release < '.escapeshellarg($fifo).'; printf cleaned; exit 0';
            $command = 'trap '.escapeshellarg($cleanup).' TERM; : > '.escapeshellarg($this->tmpDir.'/ready').'; while :; do sleep 0.05; done';
            $process = $owner->start($command, 'timeout-run', 'timeout-call', hrtime(true) - 1);
            $owner->markBackgroundedForRecord($process->id, 'timeout-run');
            $record = $store->fetchByRecordId($process->id);
            $this->assertNotNull($record);
            $deadline = hrtime(true) + 2_000_000_000;
            while (!is_file($this->tmpDir.'/ready') && hrtime(true) < $deadline) {
                $this->assertTrue($lifecycle->isAlive($process->pid));
                usleep(10_000);
            }
            $this->assertFileExists($this->tmpDir.'/ready');
            $independent = $reader->findByRecordId($process->id, 'timeout-run');
            $this->assertNotNull($independent);
            $this->assertSame(BackgroundProcessStatusEnum::Running, $independent->status);
            $this->assertNull($independent->finishedAt);
            $owner->refreshEntities(self::getContainer()->get(ProcessStore::class)->fetchAllUnfinished(['timeout-run']));
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $store->fetchByRecordId($process->id)?->status);
            $this->assertSame(BackgroundProcessStatusEnum::Running, $independent->status, 'Timeout must not refresh the suspended reader.');
            $this->assertSame(8, fwrite($release, "release\n"));
            $deadline = hrtime(true) + 2_000_000_000;
            while (null === $lifecycle->readStatusFile($record->statusPath) && hrtime(true) < $deadline) {
                usleep(10_000);
            }
            $this->assertSame(0, $lifecycle->readStatusFile($record->statusPath), 'TERM cleanup really exits successfully.');
            if ('list' === $read) {
                $this->assertSame(BackgroundProcessStatusEnum::Stopped, $reader->list('timeout-run')[0]->status);
            } else {
                $this->assertStringContainsString('cleaned', $reader->readLogTail('timeout-run', $process->pid)->content);
            }
            $this->assertSame(BackgroundProcessStatusEnum::Running, $independent->status, 'An immutable old snapshot cannot overwrite owner state.');
            $persisted = $store->snapshot($process->id);
            $this->assertNotNull($persisted);
            $this->assertNotNull($persisted->finishedAt);
            $this->assertSame(0, $persisted->exitCode);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $persisted->status);
            $this->assertFalse($persisted->stoppedByUser);
            $owner->refreshEntities(self::getContainer()->get(ProcessStore::class)->fetchAllUnfinished(['timeout-run']));
            $grace = new \ReflectionProperty($owner, 'timeoutGraceDeadlines');
            $this->assertSame([], $grace->getValue($owner));
            $poller = $this->createPoller($store, $owner, 'timeout-run');
            (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
            $this->assertCount(1, $this->sentCommands);
            $this->assertStringContainsString('finished (exit stopped)', $this->sentCommands[0][1]->text ?? '');
            $this->assertStringContainsString('cleaned', $this->sentCommands[0][1]->text ?? '');
        } finally {
            fclose($release);
            $owner->shutdownCleanup();
            $otherKernel->shutdown();
        }
    }

    public function testZeroGraceTimeoutSettlesInTheSameRefresh(): void
    {
        $store = self::getContainer()->get(ProcessStore::class);
        $config = new BackgroundProcessConfig(storageDir: $this->tmpDir, stopGraceSeconds: 0);
        $manager = new BackgroundProcessManager($store, new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore())), $config, new NullLogger());
        $process = $manager->start('sleep 10', 'test-poller-run', 'zero-grace-call', hrtime(true) - 1);
        try {
            $manager->markBackgroundedForRecord($process->id, 'test-poller-run');
            $poller = $this->createPoller($store, $manager);
            (new \ReflectionMethod($poller, 'poll'))->invoke($poller);
            $record = $store->fetchByRecordId($process->id);
            $this->assertNotNull($record);
            $this->assertNotNull($record->finishedAt);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $record->status);
            $this->assertFalse($record->stoppedByUser);
            $this->assertCount(1, $this->sentCommands);
            $this->assertStringContainsString('exit stopped', $this->sentCommands[0][1]->text ?? '');
        } finally {
            $manager->shutdownCleanup();
        }
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
        $this->assertCount(3, $selects, 'Historical children must not add per-child SELECTs.');
        $this->assertCount(2, array_filter($selects, static fn (array $record): bool => str_contains($record['context']['sql'], 'FROM background_process ')));
        $this->assertCount(1, array_filter($selects, static fn (array $record): bool => str_contains($record['context']['sql'], 'FROM background_completion_receipt ')));
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
        $this->commandBus = $this->createStub(MessageBusInterface::class);
        $this->commandBus->method('dispatch')->willReturnCallback(function (object $message) use ($em, &$published): Envelope {
            $db = $em->getConnection();
            $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM background_process WHERE finished_at IS NULL'));
            $this->assertSame($published, (int) $db->fetchOne('SELECT COUNT(*) FROM background_process WHERE completion_notified_at IS NOT NULL'), 'Each previous delivery must already be durable before the next send.');
            ++$published;
            self::getContainer()->get(ProcessStore::class)->markCompletionNotified($message->processRecordId, $message->runId());

            return new Envelope($message);
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
        $expectedFlushes = 0 === $count ? 0 : 1;
        $this->assertSame($expectedFlushes, $counter->flushes);
        $this->assertSame($expectedFlushes, $counter->changeDetectionPasses);
        $this->assertSame($expectedDeliveries, $published);
        $queries = array_filter($logger->records, static fn (array $record): bool => str_contains($record['context']['sql'] ?? '', 'session_id IN'));
        $this->assertCount(2, $queries);
        $causeReads = array_filter($logger->records, static fn (array $record): bool => str_starts_with($record['context']['sql'] ?? '', 'SELECT')
            && str_contains($record['context']['sql'], 'status = ?'));
        $this->assertCount($complete && $count > 0 ? 1 : 0, $causeReads, 'Terminal causes use one batched read, not one read per candidate or delivery.');
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
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($completed->id)?->completionNotifiedAt);
        $errors = array_filter($logger->records, static fn (array $record): bool => 'bg_process_completion.refresh_failed' === $record['message']);
        $this->assertCount(1, $errors);
        $poll->invoke($poller);
        $this->assertCount(2, $this->sentCommands);
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($failed->id)?->completionNotifiedAt);
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
            $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($process->id)?->completionNotifiedAt);
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
        $this->assertNull($process->backgroundedAt, 'Scalar queries do not mutate a stale ORM alias.');
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($process->id)?->backgroundedAt);
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($process->id)?->completionNotifiedAt);
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
        $this->assertCount(2, $candidateQueries, 'One supervision query and one scalar candidate query cover all owners.');
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
        $this->assertNotNull(self::getContainer()->get(ProcessStore::class)->snapshot($child->id)?->completionNotifiedAt);
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

        return $em->getRepository(BackgroundProcess::class)->createQueryBuilder('p')->where('p.pid = :pid')->setParameter('pid', $pid)->getQuery()->setHint(Query::HINT_REFRESH, true)->getOneOrNullResult();
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
            commandBus: $this->commandBus,
            emitter: new RuntimeEventEmitter(
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $logger ?? $this->createStub(LoggerInterface::class),
            artifactRegistry: self::getContainer()->get(AgentArtifactRegistry::class),
            receipts: self::getContainer()->get(BackgroundCompletionReceiptRepository::class),
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
