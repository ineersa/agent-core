<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run turn started. Internal model or tool steps of one response each start a turn. */
final readonly class TurnStartedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public ?string $trigger)
    {
    }
}
