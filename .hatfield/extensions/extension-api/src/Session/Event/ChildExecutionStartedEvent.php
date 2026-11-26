<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A child run owned by the session started. $sequence is the child-local observation order. */
final readonly class ChildExecutionStartedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $childRunId)
    {
    }
}
