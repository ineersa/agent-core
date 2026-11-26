<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Session\Event;

/** A background job owned by the session started. */
final readonly class BackgroundJobStartedEvent
{
    public function __construct(public string $sessionId, public string $ownerRunId, public string $jobId)
    {
    }
}
