<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A main-run assistant message completed. $text is its full visible text, empty for tool-only messages. */
final readonly class AssistantMessageCompletedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $text)
    {
    }
}
