<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Clears disposable run-control projection rows once the controller owns the session. */
final readonly class RunOperationalProjectionControllerSessionLifecycleListener
{
    public function __construct(
        private RunOperationalProjectionRepository $projectionRepository,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class, priority: 256)]
    public function onSessionStarting(ControllerSessionStartingEvent $event): void
    {
        // HeadlessController holds the project/session owner lock while this
        // synchronous listener runs, before it launches any consumer.
        $this->projectionRepository->deleteForOwnerSession($event->sessionId);
    }
}
