<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A pending question or approval of the main run, or of the child named by $childRunId, was resolved. */
final readonly class InputResolvedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $requestId, public ?string $childRunId = null)
    {
    }
}
