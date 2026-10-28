<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/** Only the tool requester uses the independent reply queue. Controllers never do. */
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
        return [BackgroundProcessManager::class, BashRegistrationClient::class];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        if (!\in_array('tool', $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }
        $this->container->get(BackgroundProcessManager::class)->useLifecycleOwner($this->container->get(BashRegistrationClient::class));
    }
}
