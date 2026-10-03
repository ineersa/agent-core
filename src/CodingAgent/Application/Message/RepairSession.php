<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

final readonly class RepairSession
{
    public function __construct(public string $runId, public bool $apply, public string $commandId)
    {
    }
}
