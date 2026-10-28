<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Scheduling\DTO;

final readonly class ExecuteSessionScheduleDTO
{
    public function __construct(public string $runId, public string $id, public string $invocationId)
    {
    }
}
