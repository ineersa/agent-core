<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after controller consumers stop and before session ownership releases,
 * or before explicit session deletion removes canonical metadata and files.
 */
final class ControllerSessionShutdownEvent extends Event
{
    public function __construct(
        public readonly string $sessionId,
    ) {
        if ('' === $sessionId) {
            throw new \InvalidArgumentException('Controller session ID must not be empty.');
        }
    }
}
