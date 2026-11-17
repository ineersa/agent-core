<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

final readonly class SessionDeletedEvent
{
    public function __construct(public string $runId, public string $cwd, public ?string $parentRunId)
    {
    }
}
