<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\CodingAgent\Runtime\Contract\RepairResult;

interface SessionRepairServiceInterface
{
    public function repair(string $runId, bool $apply): RepairResult;
}
