<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\CodingAgent\Application\Pipeline\ChildInvocationLifecycleService;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/** Each requester process uses its own fixed reply role. */
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
        return [BackgroundProcessManager::class, BashRegistrationClient::class, ChildInvocationLifecycleService::class, DeferredToolCompletionRepository::class, ToolPayloadMiddleware::class, 'coding_agent.bash_scheduler_client' => BashRegistrationClient::class];
    }

    public function onStarted(WorkerStartedEvent $event): void
    {
        $transports = $event->getWorker()->getMetadata()->getTransportNames();
        if (\in_array('scheduler_default', $transports, true)) {
            $this->container->get(BackgroundProcessManager::class)->useProcessOwner($this->container->get('coding_agent.bash_scheduler_client'));

            return;
        }
        if (!\in_array('tool', $transports, true)) {
            return;
        }
        $this->container->get(BackgroundProcessManager::class)->useLifecycleOwner($this->container->get(BashRegistrationClient::class));
        $this->container->get(ChildInvocationLifecycleService::class)->useOwner($this->container->get(BashRegistrationClient::class));
        $this->container->get(DeferredToolCompletionRepository::class)->useOwner($this->container->get(BashRegistrationClient::class));
        $this->container->get(ToolPayloadMiddleware::class)->useRemoteResultOwner();
    }
}
