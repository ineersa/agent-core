<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Thesis: controller lifecycle events remove accepted process state only for
 * their parent session and registered child runs, including a running process.
 */
#[CoversClass(BackgroundProcessControllerSessionLifecycleListener::class)]
#[CoversClass(ControllerSessionStartingEvent::class)]
#[CoversClass(ControllerSessionShutdownEvent::class)]
final class BackgroundProcessControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    private string $tmpDir;
    private ProcessStore $store;
    private ProcessLifecycle $lifecycle;
    private BackgroundProcessManager $manager;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('bg-controller-lifecycle');
        $config = new BackgroundProcessConfig(storageDir: $this->tmpDir, stopGraceSeconds: 0);
        $logger = new TestLogger();
        $this->store = static::getContainer()->get(ProcessStore::class);
        $this->lifecycle = new ProcessLifecycle($config, $logger);
        $this->manager = new BackgroundProcessManager($this->store, $this->lifecycle, $config, $logger);
        $listener = new BackgroundProcessControllerSessionLifecycleListener(
            static::getContainer()->get(BackgroundProcessCompletionPoller::class),
            $this->store,
            $this->manager,
            $this->lifecycle,
            $logger,
        );
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(ControllerSessionStartingEvent::class, $listener->onSessionStarting(...));
        $this->dispatcher->addListener(ControllerSessionShutdownEvent::class, $listener->onSessionShutdown(...));
    }

    protected function tearDown(): void
    {
        $this->manager->shutdownCleanup();
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function startingEventCleansParentAndChildAcceptedStateButRetainsForeignAndUnsafeRows(): void
    {
        $registry = static::getContainer()->get(AgentArtifactRegistry::class);
        $registry->create('parent-run', 'child-artifact', 'child-run', 'scout', AgentArtifactKindEnum::Subagent);

        $parent = $this->startAccepted('parent-run', 'parent');
        $child = $this->startAccepted('child-run', 'child');
        $foreign = $this->startAccepted('foreign-run', 'foreign');
        $unsafe = $this->createUnsafeAccepted('parent-run');

        $this->dispatcher->dispatch(new ControllerSessionStartingEvent('parent-run'));
        $this->dispatcher->dispatch(new ControllerSessionStartingEvent('parent-run'));

        $this->assertFalse($this->store->existsByRecordId($parent['id']));
        $this->assertFalse($this->store->existsByRecordId($child['id']));
        $this->assertFileDoesNotExist($parent['log']);
        $this->assertFileDoesNotExist($child['status']);
        // OS liveness after stop is covered by ProcessLifecycleTest and
        // BackgroundProcessManagerTest; this case owns session-scoped row/sidecar cleanup.

        $this->assertTrue($this->store->existsByRecordId($foreign['id']));
        $this->assertFileExists($foreign['log']);
        $this->assertTrue($this->store->existsByRecordId($unsafe['id']));
        $this->assertFileExists($unsafe['log']);
    }

    #[Test]
    public function shutdownEventCleansAcceptedState(): void
    {
        $accepted = $this->startAccepted('shutdown-run', 'shutdown');

        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('shutdown-run'));
        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('shutdown-run'));

        $this->assertFalse($this->store->existsByRecordId($accepted['id']));
        $this->assertFileDoesNotExist($accepted['log']);
        $this->assertFileDoesNotExist($accepted['status']);
        $this->assertFileDoesNotExist($accepted['pid_file']);
        // OS liveness after stop is covered by ProcessLifecycleTest and
        // BackgroundProcessManagerTest; this case owns session-scoped row/sidecar cleanup.
    }

    /** @return iterable<string, array{string, bool}> */
    public static function unprovenAcceptedRows(): iterable
    {
        foreach (['legacy', 'foreign', 'wrong-session', 'invalid-marker', 'wrong-status'] as $case) {
            yield $case.' starting' => [$case, true];
            yield $case.' shutdown' => [$case, false];
        }
    }

    #[Test]
    #[DataProvider('unprovenAcceptedRows')]
    public function automaticCleanupDoesNotStopUnfinishedUnprovenRows(string $case, bool $starting): void
    {
        $session = 'protected-run';
        if ('legacy' === $case) {
            $log = $this->tmpDir.'/legacy.log';
            $status = $this->tmpDir.'/legacy.status';
        } else {
            $lifecycle = 'foreign' === $case
                ? new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $this->tmpDir.'/foreign'), new TestLogger())
                : $this->lifecycle;
            $launch = $lifecycle->allocateManagedLaunch('wrong-session' === $case ? 'other-run' : $session);
            $log = $launch->logPath;
            $status = $launch->statusPath;
            if ('invalid-marker' === $case) {
                file_put_contents(\dirname($log).'/owner.json', '{}');
            } elseif ('wrong-status' === $case) {
                $status = \dirname($log).'/wrong.status';
            }
            $launch = null;
        }
        file_put_contents($log, 'retained');
        $id = $this->store->insertRecord([
            'pid' => \PHP_INT_MAX,
            'pgid' => null,
            'session_id' => $session,
            'command' => 'fixture',
            'log_path' => $log,
            'status_path' => $status,
            'started_at' => new \DateTimeImmutable(),
        ]);
        $this->manager->markBackgroundedForRecord($id, $session);
        $event = $starting ? new ControllerSessionStartingEvent($session) : new ControllerSessionShutdownEvent($session);
        $this->dispatcher->dispatch($event);
        $this->dispatcher->dispatch($event);

        $entity = $this->store->fetchByRecordId($id);
        $this->assertNotNull($entity);
        // stopByRecordId() would refresh this absent PID into a finished row,
        // even without sending a signal. Remaining unfinished proves it was
        // never called; no real or protected process is used as a sentinel.
        $this->assertNull($entity->finishedAt);
        $this->assertFileExists($log);
        $this->assertFileDoesNotExist($status);
    }

    /**
     * @return array{id: int, pid: int, log: string, status: string, pid_file: string}
     */
    private function startAccepted(string $sessionId, string $prefix): array
    {
        $result = $this->manager->start('exec sleep 30', $sessionId);
        $this->manager->markBackgroundedForRecord($result->id, $sessionId);

        return [
            'id' => $result->id,
            'pid' => $result->pid,
            'log' => $result->logPath,
            'status' => substr($result->logPath, 0, -4).'.status',
            'pid_file' => substr($result->logPath, 0, -4).'.pid',
        ];
    }

    /**
     * @return array{id: int, log: string}
     */
    private function createUnsafeAccepted(string $sessionId): array
    {
        $unsafeDir = $this->tmpDir.'/nested';
        mkdir($unsafeDir);
        $logPath = $unsafeDir.'/unsafe.log';
        $statusPath = $unsafeDir.'/unsafe.status';
        file_put_contents($logPath, 'output');
        file_put_contents($statusPath, '0');
        file_put_contents($unsafeDir.'/unsafe.pid', '999999');
        $id = $this->store->insertRecord([
            'pid' => 999999,
            'pgid' => null,
            'session_id' => $sessionId,
            'command' => 'fixture',
            'log_path' => $logPath,
            'status_path' => $statusPath,
            'started_at' => new \DateTimeImmutable(),
        ]);
        $entity = $this->store->fetchByRecordId($id);
        $this->assertNotNull($entity);
        $entity->finish(0, new \DateTimeImmutable());
        $this->store->flush();
        $this->manager->markBackgroundedForRecord($id, $sessionId);

        return ['id' => $id, 'log' => $logPath];
    }
}
