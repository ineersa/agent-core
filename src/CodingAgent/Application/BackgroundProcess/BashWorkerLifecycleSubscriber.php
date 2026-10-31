<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/** Tool workers seal deferred results for owner admission. */
final readonly class BashWorkerLifecycleSubscriber implements EventSubscriberInterface, ServiceSubscriberInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [WorkerStartedEvent::class => 'onStarted'];
    }

    public static function getSubscribedServices(): array
    {
        return [BackgroundProcessManager::class, ToolPayloadMiddleware::class];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $transports = $event->getWorker()->getMetadata()->getTransportNames();
        if (\in_array('scheduler_default', $transports, true)) {
            // Scheduler maintenance must publish one-way process intents only.
            $this->container->get(BackgroundProcessManager::class)->useRemoteLifecycleOwner();

            return;
        }
        if (!\in_array('tool', $transports, true)) {
            return;
        }
        $this->container->get(BackgroundProcessManager::class)->useRemoteLifecycleOwner();
        $this->container->get(ToolPayloadMiddleware::class)->useRemoteResultOwner();
    }
}
