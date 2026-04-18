<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final readonly class RunEventDispatcher
{
    public function __construct(
        private EventSubscriberRegistry $registry,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(RunEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, $event->type);

        foreach ($this->registry->subscribersFor($event->type) as $subscriber) {
            $subscriber->onEvent($event);
        }
    }
}
