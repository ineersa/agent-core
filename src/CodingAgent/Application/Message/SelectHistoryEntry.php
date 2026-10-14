<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

final readonly class SelectHistoryEntry
{
    public function __construct(public string $runId, public int $turnNo, public string $commandId, public string $role = 'user')
    {
    }
}
