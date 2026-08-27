<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tool\OutputCap;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Removes ephemeral output-cap artifacts owned by the controller session. */
final class OutputCapControllerSessionLifecycleListener
{
    public function __construct(
        private readonly BackgroundProcessCompletionPoller $completionPoller,
        private readonly OutputCap $outputCap,
    ) {
    }

    #[AsEventListener(event: ControllerSessionStartingEvent::class)]
    public function onStarting(ControllerSessionStartingEvent $event): void
    {
        $this->cleanup($event->sessionId, 'starting');
    }

    #[AsEventListener(event: ControllerSessionShutdownEvent::class)]
    public function onShutdown(ControllerSessionShutdownEvent $event): void
    {
        $this->cleanup($event->sessionId, 'shutdown');
    }

    private function cleanup(string $sessionId, string $phase): void
    {
        foreach ($this->completionPoller->resolveOwnedSessionIds($sessionId) as $ownedRunId) {
            $this->outputCap->cleanupRun($ownedRunId, $phase);
        }
    }
}
