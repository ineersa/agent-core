<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

interface SessionRepairServiceInterface
{
    public function repair(string $runId, bool $apply): RepairResult;
}
