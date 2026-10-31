<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Native execution/replay proofs. Socket barriers hold commands without scheduling races. */
final class BackgroundProcessManagerTest extends IsolatedKernelTestCase
{
    private string $directory;
    private string $runId;
    private ProcessStore $store;
    private BackgroundProcessManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = TestDirectoryIsolation::createProjectTempDir('bash-native-replay');
        $this->assertSame($this->directory, realpath($this->directory));
        $this->runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('native replay');
        $this->store = self::getContainer()->get(ProcessStore::class);
        $this->manager = $this->newManager();
    }

    protected function tearDown(): void
    {
        foreach ($this->store->sessionSnapshots($this->runId) as $record) {
            $this->manager->stopByRecordId($record->id, $this->runId);
            $this->assertTrue($this->manager->cleanupFinishedRecord($record->id, $this->runId));
        }
        $this->manager->cleanupSessionSidecars($this->runId);
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testOrdinaryOutputAndCompletedIntentReplay(): void
    {
        $marker = $this->directory.'/execution-count';
        $command = 'printf x >> '.escapeshellarg($marker).'; printf ordinary-output';
        $this->assertSame('ordinary-output', $this->invoke($this->manager, $command));
        $listener = $this->sessionListener();
        $listener->onSessionShutdown(new ControllerSessionShutdownEvent($this->runId));
        $listener->onSessionStarting(new ControllerSessionStartingEvent($this->runId));
        $this->assertSame('ordinary-output', $this->invoke($this->newManager(), $command));
        $this->assertSame('x', file_get_contents($marker));
        $records = $this->store->sessionSnapshots($this->runId);
        $this->assertCount(1, $records);
        $this->assertSame(0, $records[0]->exitCode);
        $this->assertFileExists($records[0]->logPath);
        $this->assertFileExists($records[0]->statusPath);
        // A crash before the SQL insert must retain the same native evidence too.
        $this->assertTrue(BackgroundProcessFixture::delete(self::getContainer(), $records[0]->id));
        $listener->onSessionShutdown(new ControllerSessionShutdownEvent($this->runId));
        $listener->onSessionStarting(new ControllerSessionStartingEvent($this->runId));
        $this->assertSame('ordinary-output', $this->invoke($this->newManager(), $command));
        $this->assertSame('x', file_get_contents($marker));

        $listener->onSessionShutdown(new ControllerSessionShutdownEvent($this->runId, permanentDeletion: true));
        $this->assertSame([], $this->store->sessionSnapshots($this->runId));
        $this->assertFileDoesNotExist($records[0]->logPath);
        $this->assertFileDoesNotExist($records[0]->statusPath);
    }

    public function testRunningIntentReattachesWithoutSecondLaunch(): void
    {
        [$server, $command, $marker] = $this->blockedCommand();
        $peer = null;
        try {
            $deadline = hrtime(true) + 30_000_000_000;
            $first = $this->manager->start($command, $this->runId, 'call', $deadline, invocationKey: 'same-intent');
            $this->assertSame($deadline, $first->deadlineNanoseconds, 'Fresh launch retains the exact supplied deadline.');
            $peer = stream_socket_accept($server, 2);
            $this->assertIsResource($peer, 'The child connects after recording its launch.');
            $other = $this->newManager();
            $second = $other->start($command, $this->runId, 'call', hrtime(true) + 60_000_000_000, invocationKey: 'same-intent');
            $this->assertSame($first->pid, $second->pid);
            $this->assertSame($first->statusPath, $second->statusPath);
            $this->assertSame($first->deadlineNanoseconds, $second->deadlineNanoseconds);
            $this->assertSame('x', file_get_contents($marker));
            $this->assertCount(1, $this->store->sessionSnapshots($this->runId));
            $this->assertSame(1, $other->shutdownCleanup());
        } finally {
            if (\is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    public function testBackgroundAcknowledgmentIsDurableWithoutOwnerReceipt(): void
    {
        [$server, $command, $marker] = $this->blockedCommand();
        $peer = null;
        try {
            $acknowledgment = $this->invoke($this->manager, $command, true);
            $peer = stream_socket_accept($server, 2);
            $this->assertIsResource($peer);
            $this->assertStringContainsString('Command moved to background.', $acknowledgment);
            $records = $this->store->backgroundSnapshots($this->runId);
            $this->assertCount(1, $records, 'No owner bus or receipt is involved.');
            $this->assertNotNull($records[0]->backgroundedAt);
            $this->assertSame(0, $this->manager->shutdownCleanup());
            $other = $this->newManager();
            $this->assertSame($acknowledgment, $this->invoke($other, $command, true));
            $this->assertSame(0, $other->shutdownCleanup(), 'Replayed acceptance never takes foreground ownership.');
            $this->assertSame('x', file_get_contents($marker));
            $this->sessionListener()->onSessionShutdown(new ControllerSessionShutdownEvent($this->runId));
            $stopped = $this->store->sessionSnapshots($this->runId);
            $this->assertCount(1, $stopped);
            $this->assertNotNull($stopped[0]->finishedAt);
            $this->assertFileExists($stopped[0]->logPath);
            $this->assertFileExists($stopped[0]->statusPath);
        } finally {
            if (\is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    public function testLaunchWithoutSqlRowIsRecoveredFromItsNativeClaim(): void
    {
        [$server, $command, $marker] = $this->blockedCommand();
        $peer = null;
        try {
            $first = $this->manager->start($command, $this->runId, 'call', hrtime(true) + 30_000_000_000, invocationKey: 'same-intent');
            $peer = stream_socket_accept($server, 2);
            $this->assertIsResource($peer);
            $id = $this->store->recordIdForStatusPath($first->statusPath, $this->runId);
            $this->assertNotNull($id);
            $this->assertTrue(BackgroundProcessFixture::delete(self::getContainer(), $id));
            $other = $this->newManager();
            $recovered = $other->start($command, $this->runId, 'call', hrtime(true) + 30_000_000_000, invocationKey: 'same-intent');
            $this->assertSame($first->pid, $recovered->pid);
            $this->assertSame('x', file_get_contents($marker));
            $records = $this->store->sessionSnapshots($this->runId);
            $this->assertCount(1, $records);
            $this->assertSame(filemtime(substr($first->statusPath, 0, -7).'.pid'), $records[0]->startedAt?->getTimestamp());
            $other->shutdownCleanup();
        } finally {
            if (\is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    public function testInsertFailureStopsOwnedLaunchAndRetainsReplayEvidence(): void
    {
        $fifo = $this->directory.'/insert-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $command = 'read -r release < '.escapeshellarg($fifo);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE TEMP TRIGGER reject_bash_insert BEFORE INSERT ON background_process BEGIN SELECT RAISE(FAIL, 'injected launch insert failure'); END");
        try {
            try {
                $this->manager->start($command, $this->runId, 'call', invocationKey: 'failed-insert');
                $this->fail('The database trigger must reject insertion.');
            } catch (DbalException $error) {
                $this->assertStringContainsString('injected launch insert failure', $error->getMessage());
            }
        } finally {
            $connection->executeStatement('DROP TRIGGER reject_bash_insert');
        }
        $pidFiles = glob($this->directory.'/bg/*.pid');
        $this->assertIsArray($pidFiles);
        $this->assertCount(1, $pidFiles);
        $pid = (int) file_get_contents($pidFiles[0]);
        $status = substr($pidFiles[0], 0, -4).'.status';
        $lifecycle = new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $this->directory.'/bg'), new NullLogger(), new LockFactory(new FlockStore()));
        $this->assertFalse($lifecycle->isAlive($pid), 'Failed storage must not leave an owned foreground process running.');
        $this->assertFileExists(substr($pidFiles[0], 0, -4).'.log');
        $this->assertSame([], $this->store->sessionSnapshots($this->runId));
        $replay = $this->newManager()->start($command, $this->runId, 'call', invocationKey: 'failed-insert');
        $this->assertSame($pid, $replay->pid, 'Retry must recover the stopped execution, never launch again.');
        $this->assertSame($status, $replay->statusPath);
        $this->assertCount(1, $this->store->sessionSnapshots($this->runId));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testAcceptedLaunchSurvivesThrowingLogger(bool $recordCandidate): void
    {
        [$server, $command, $marker] = $this->blockedCommand();
        $peer = null;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message): void {
            if ('background_process.marked_backgrounded' === $message) {
                throw new \RuntimeException('injected acceptance logging failure');
            }
        });
        $manager = $this->newManager($logger);
        try {
            $launch = $manager->start($command, $this->runId, 'call', invocationKey: 'accepted-logger');
            $peer = stream_socket_accept($server, 2);
            $this->assertIsResource($peer);
            $candidate = $manager->resolveBackgroundAcceptance($launch->statusPath, $this->runId);
            $this->assertNotNull($candidate);
            if ($recordCandidate) {
                $candidate['kind'] = 'record';
                $candidate['recordId'] = $this->store->recordIdForStatusPath($launch->statusPath, $this->runId);
                $this->assertNotNull($candidate['recordId']);
            }
            try {
                $manager->acceptResolvedBackground($candidate);
                $this->fail('Acceptance logging must throw.');
            } catch (\RuntimeException $error) {
                $this->assertSame('injected acceptance logging failure', $error->getMessage());
            }
            $manager->releaseNativeInvocation($launch->statusPath);
            $this->assertSame(0, $manager->shutdownCleanup());
            $other = $this->newManager();
            $replay = $other->start($command, $this->runId, 'call', invocationKey: 'accepted-logger');
            $this->assertTrue($replay->accepted);
            $this->assertSame($launch->pid, $replay->pid);
            $this->assertSame(0, $other->shutdownCleanup());
            $this->assertSame('x', file_get_contents($marker));
            $this->assertNull($this->store->sessionSnapshots($this->runId)[0]->finishedAt);
        } finally {
            foreach ($this->store->sessionSnapshots($this->runId) as $record) {
                $manager->stopByRecordId($record->id, $this->runId);
            }
            $manager->shutdownCleanup();
            if (\is_resource($peer)) {
                fclose($peer);
            }
            fclose($server);
        }
    }

    private function sessionListener(): BackgroundProcessControllerSessionLifecycleListener
    {
        return new BackgroundProcessControllerSessionLifecycleListener(self::getContainer()->get(BackgroundProcessCompletionPoller::class), $this->store, $this->manager, new NullLogger());
    }

    private function newManager(?LoggerInterface $logger = null): BackgroundProcessManager
    {
        $config = new BackgroundProcessConfig(storageDir: $this->directory.'/bg', stopGraceSeconds: 0);
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));

        return new BackgroundProcessManager($this->store, $lifecycle, $config, $logger ?? new NullLogger());
    }

    private function invoke(BackgroundProcessManager $manager, string $command, bool $backgrounded = false): string
    {
        $accessor = new StackToolExecutionContextAccessor();
        $tool = new BashTool($manager, $accessor, new ToolRuntime($accessor), new NullLogger(), self::getContainer()->get(ToolPayloadStoreInterface::class), new BashToolConfig(pollIntervalMicros: 1_000));

        return $accessor->with(
            new ToolContext($this->runId, 1, 'call', 'bash', new NullCancellationToken(), 30, stepId: 'step'),
            static fn (): string => $tool(new BashArgumentsDTO(command: $command, backgrounded: $backgrounded)),
        );
    }

    /** @return array{resource, string, string} */
    private function blockedCommand(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $port = substr($address, strrpos($address, ':') + 1);
        $marker = $this->directory.'/execution-count';
        $command = 'printf x >> '.escapeshellarg($marker).'; exec 3<>/dev/tcp/127.0.0.1/'.$port.'; IFS= read -r release <&3; printf released';

        return [$server, $command, $marker];
    }
}
