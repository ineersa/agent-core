<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** The main run completed. */
final readonly class TurnCompletedEvent
{
    public function __construct(public string $sessionId, public int $sequence)
    {
    }
}
