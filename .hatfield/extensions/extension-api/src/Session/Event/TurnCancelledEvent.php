<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run turn or the main run was cancelled. */
final readonly class TurnCancelledEvent
{
    public function __construct(public string $sessionId, public int $sequence)
    {
    }
}
