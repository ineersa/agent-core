<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Event;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Tool\ToolCompletionDeliveryEligibilityPolicy;
use Ineersa\AgentCore\Contract\Tool\OwningRootSessionResolverInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\CanonicalToolCompletionDeliveryFenceProvider;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Event\RunOperationalProjectionControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ToolCompletionControllerGenerationListener;
use Ineersa\CodingAgent\Session\RunHistoryIndex;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class ToolCompletionControllerGenerationListenerTest extends IsolatedKernelTestCase
{
    public function testRestartFenceSurvivesOperationalDeletionAndColdReaders(): void
    {
        $c = self::getContainer();
        [$run] = ToolCompletionReceiptFixture::create($c);
        $provider = $c->get(CanonicalToolCompletionDeliveryFenceProvider::class);
        $old = $provider->capture($run);
        $projection = $c->get(RunOperationalProjectionRepository::class);
        $state = new RunState($run, RunStatus::Running);
        $projection->replace($state);
        $event = new ControllerSessionStartingEvent($run);
        $c->get(ToolCompletionControllerGenerationListener::class)->onSessionStarting($event);
        $c->get(RunOperationalProjectionControllerSessionLifecycleListener::class)->onSessionStarting($event);
        $this->assertNull($projection->findOperationalStatus($run));
        $c->get(EntityManagerInterface::class)->clear();
        $cold = new CanonicalToolCompletionDeliveryFenceProvider($c->get(SessionRunEventStore::class), $c->get(RunHistoryIndex::class), $c->get(OwningRootSessionResolverInterface::class), $c->get(HatfieldSessionRepository::class));
        $this->assertSame($old->controllerGeneration + 1, $cold->capture($run)->controllerGeneration);
        $this->assertSame($old->wakeGeneration, $cold->capture($run)->wakeGeneration);
        $this->assertSame('stale_controller_generation', (new ToolCompletionDeliveryEligibilityPolicy($cold))->ineligibleReason($state, $old));
        $c->get(ToolCompletionControllerGenerationListener::class)->onSessionStarting($event);
        $this->assertSame($old->controllerGeneration + 2, $cold->capture($run)->controllerGeneration);
    }
}
