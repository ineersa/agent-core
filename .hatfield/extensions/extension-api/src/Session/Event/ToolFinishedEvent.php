<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run tool execution completed, failed, was cancelled or acknowledged. */
final readonly class ToolFinishedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $toolCallId)
    {
    }
}
