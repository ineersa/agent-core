<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** Final graceful quit of the selected session. Not dispatched for reload or session switch. */
final readonly class SessionClosingEvent
{
    public function __construct(public string $sessionId)
    {
    }
}
