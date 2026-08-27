<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCompletionPoller;
use Ineersa\CodingAgent\Runtime\Controller\OutputCapControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\OutputCap;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Thesis: start/shutdown clean only the parent and registered-child ephemeral
 * output scopes; lifecycle events are idempotent and preserve foreign scopes.
 */
final class OutputCapControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    private string $tmpDir;
    private OutputCap $outputCap;
    private TestLogger $logger;
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('output-cap-controller-lifecycle');
        $this->logger = new TestLogger();
        $this->outputCap = new OutputCap(
            new OutputCapConfig(storageDir: $this->tmpDir),
            new LockFactory(new FlockStore($this->tmpDir)),
            $this->logger,
        );
        $listener = new OutputCapControllerSessionLifecycleListener(
            static::getContainer()->get(BackgroundProcessCompletionPoller::class),
            $this->outputCap,
        );
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(ControllerSessionStartingEvent::class, $listener->onStarting(...));
        $this->dispatcher->addListener(ControllerSessionShutdownEvent::class, $listener->onShutdown(...));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function startAndShutdownCleanParentAndChildScopesWithoutTouchingForeignScope(): void
    {
        static::getContainer()->get(AgentArtifactRegistry::class)->create(
            'parent-run',
            'child-artifact',
            'child-run',
            'scout',
            AgentArtifactKindEnum::Subagent,
        );
        $parent = $this->outputCap->persist('parent', 'parent-run');
        $child = $this->outputCap->persist('child', 'child-run');
        $foreign = $this->outputCap->persist('foreign', 'foreign-run');

        $this->dispatcher->dispatch(new ControllerSessionStartingEvent('parent-run'));
        $this->dispatcher->dispatch(new ControllerSessionStartingEvent('parent-run'));

        $this->assertFileDoesNotExist($parent);
        $this->assertFileDoesNotExist($child);
        $this->assertFileExists($foreign);

        $shutdown = $this->outputCap->persist('shutdown', 'parent-run');
        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('parent-run'));
        $this->dispatcher->dispatch(new ControllerSessionShutdownEvent('parent-run'));
        $this->assertFileDoesNotExist($shutdown);

        $records = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => 'output_cap.session_cleanup_completed' === $record['message'],
        ));
        $this->assertNotEmpty($records);
        $context = $records[0]['context'];
        $this->assertSame('tool.output_cap', $context['component']);
        $this->assertSame('output_cap.session_cleanup_completed', $context['event_type']);
        $this->assertArrayHasKey('lifecycle_phase', $context);
        $this->assertArrayHasKey('removed_file_count', $context);
        $this->assertArrayHasKey('removed_bytes', $context);
        $this->assertStringNotContainsString('parent-run', json_encode($context, \JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($this->tmpDir, json_encode($context, \JSON_THROW_ON_ERROR));
    }
}
