<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

final readonly class SelectHistoryPrompt
{
    public function __construct(public string $runId, public int $turnNo)
    {
    }
}
