<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Contracts\Service\ServiceSubscriberInterface;

/** Terminal transport failure is an unknown outcome, never permission to relaunch. */
final readonly class BashLifecycleFailureSubscriber implements EventSubscriberInterface, ServiceSubscriberInterface
{
    public function __construct(private ContainerInterface $container, private string $sessionId)
    {
    }

    public static function getSubscribedServices(): array
    {
        return [BashRegistrationReplyPublisher::class];
    }

    public static function getSubscribedEvents(): array
    {
        // Native retry listener runs at 100; owner Doctrine recovery runs at 50.
        return [WorkerMessageFailedEvent::class => ['onFailed', -10]];
    }

    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        if ('run_control' !== $event->getReceiverName() || $event->willRetry()) {
            return;
        }
        $command = $event->getEnvelope()->getMessage();
        if (!$command instanceof RegisterBashProcessCommandDTO && !$command instanceof BashOwnershipCommandDTO && !$command instanceof BashProcessCommandDTO) {
            return;
        }
        $this->container->get(BashRegistrationReplyPublisher::class)->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, failed: true, role: $command->role));
    }
}
