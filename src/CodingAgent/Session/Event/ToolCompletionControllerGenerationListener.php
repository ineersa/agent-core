<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The controller owns its session lock before this synchronous startup event. */
final readonly class ToolCompletionControllerGenerationListener
{
    public function __construct(private HatfieldSessionRepository $sessions)
    {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class, priority: 512)]
    public function onSessionStarting(ControllerSessionStartingEvent $event): void
    {
        // This lives on the durable session, not the disposable operational rows
        // cleared by the next listener. Every cold controller start revokes old wake permission.
        $this->sessions->advanceControllerGeneration($event->sessionId);
    }
}
