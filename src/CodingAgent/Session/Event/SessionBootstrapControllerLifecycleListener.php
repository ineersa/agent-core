<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Release abandoned transfers before a replacement owner starts and on disconnect. */
final readonly class SessionBootstrapControllerLifecycleListener
{
    public function __construct(private SessionBootstrapSpoolStore $spools, private HatfieldSessionStore $sessions)
    {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    #[AsEventListener(event: ControllerSessionShutdownEvent::class)]
    public function clear(ControllerSessionStartingEvent|ControllerSessionShutdownEvent $event): void
    {
        // A new draft controller may start before its session is persisted.
        if ($this->sessions->exists($event->sessionId)) {
            $this->spools->cancel($event->sessionId);
        }
    }
}
