<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** The main run, or the child named by $childRunId, waits for a question answer or an approval. */
final readonly class InputRequestedEvent
{
    public function __construct(public string $sessionId, public int $sequence, public string $requestId, public InputRequestTypeEnum $type, public ?string $childRunId = null)
    {
    }
}
