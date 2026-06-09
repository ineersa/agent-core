<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Integration tests for BackgroundProcessCompletionPoller.
 *
 * All key deps (ProcessStore, BackgroundProcessManager) are final and
 * cannot be mocked. We use real instances with a real DB and filesystem.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller
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

            public function resume(string $runId): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected in test');
            }

            public function send(string $runId, UserCommand $command): void
            {
                $this->sentCommands[] = [$runId, $command];
            }

            public function events(string $runId): iterable
            {
                return [];
            }

            public function cancel(string $runId): void
            {
            }

            public function shellExecute(string $command, string $sessionId, string $cwd): \Ineersa\CodingAgent\Runtime\Contract\RunHandle
            {
                throw new \RuntimeException('Not expected in test');
            }
        };

        $this->tmpDir = sys_get_temp_dir().'/hatfield_poller_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);

        parent::tearDown();
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
        $this->assertSame('follow_up', $command->type);
        $this->assertStringContainsString('[BG_PROCESS_DONE]', $command->text ?? '');
        $this->assertStringContainsString('PID 10001', $command->text ?? '');
        $this->assertStringContainsString('Hello world', $command->text ?? '');

        $entity = $this->findProcess(10001);
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->completionNotifiedAt);
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
                eventClient: null,
                boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $this->createStub(LoggerInterface::class),
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
        // The poller must refresh unfinished process statuses before
        // querying for pending notifications.
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

        $poller = $this->createPoller($store, $manager);
        $ref = new \ReflectionMethod($poller, 'poll');
        $ref->invoke($poller);

        // Assert follow_up was sent with [BG_PROCESS_DONE] prefix
        $this->assertCount(1, $this->sentCommands);
        [$runId, $command] = $this->sentCommands[0];
        $this->assertSame('test-regression-run', $runId);
        $this->assertSame('follow_up', $command->type);
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

    private function createPoller(ProcessStore $store, BackgroundProcessManager $manager): BackgroundProcessCompletionPoller
    {
        return new BackgroundProcessCompletionPoller(
            processStore: $store,
            processManager: $manager,
            sessionClient: $this->clientSpy,
            emitter: new RuntimeEventEmitter(
                eventClient: null,
                boundary: new RuntimeExceptionBoundary(new EventDispatcher()),
                logger: $this->createStub(LoggerInterface::class),
            ),
            logger: $this->createStub(LoggerInterface::class),
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
