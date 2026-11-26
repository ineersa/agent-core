<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** The selected session's display title changed outside a turn, e.g. by a rename. */
final readonly class SessionTitleChangedEvent
{
    public function __construct(public string $sessionId, public string $title)
    {
    }
}
