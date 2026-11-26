<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run tool execution started. */
final readonly class ToolStartedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $toolCallId, public ?string $toolName)
    {
    }
}
