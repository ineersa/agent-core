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

        $this->assertNull($this->store->fetchById($parent['id']));
        $this->assertNull($this->store->fetchById($child['id']));
        $this->assertFileDoesNotExist($parent['log']);
        $this->assertFileDoesNotExist($child['status']);
        $this->assertFalse($this->lifecycle->isAlive($parent['pid']));

        $this->assertNotNull($this->store->fetchById($foreign['id']));
        $this->assertFileExists($foreign['log']);
        $this->assertNotNull($this->store->fetchById($unsafe['id']));
        $this->assertFileExists($unsafe['log']);
    }

    #[Test]
    public function shutdownEventCleansAcceptedState(): void
    {
        $accepted = $this->startAccepted('shutdown-run', 'shutdown');

        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('shutdown-run'));
        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('shutdown-run'));

        $this->assertNull($this->store->fetchById($accepted['id']));
        $this->assertFileDoesNotExist($accepted['log']);
        $this->assertFileDoesNotExist($accepted['status']);
        $this->assertFileDoesNotExist($accepted['pid_file']);
        $this->assertFalse($this->lifecycle->isAlive($accepted['pid']));
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
        $this->store->markFinished($id, 0, new \DateTimeImmutable());
        $this->manager->markBackgroundedForRecord($id, $sessionId);

        return ['id' => $id, 'log' => $logPath];
    }
}
