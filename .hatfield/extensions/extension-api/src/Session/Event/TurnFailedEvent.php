<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run turn or the main run failed. */
final readonly class TurnFailedEvent
{
    public function __construct(public string $sessionId, public int $sequence)
    {
    }
}
