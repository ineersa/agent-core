<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** Selection of an established session: a new or resumed session, or a promoted draft. */
final readonly class SessionSelectedEvent
{
    public function __construct(public string $sessionId, public bool $resumed)
    {
    }
}
