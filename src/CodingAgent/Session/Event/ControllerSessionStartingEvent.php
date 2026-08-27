<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a controller exclusively owns a session and before it starts consumers.
 */
final class ControllerSessionStartingEvent extends Event
{
    public function __construct(
        public readonly string $sessionId,
    ) {
        if ('' === $sessionId) {
            throw new \InvalidArgumentException('Controller session ID must not be empty.');
        }
    }
}
