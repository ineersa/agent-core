<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

use Ineersa\CodingAgent\Runtime\Contract\RepairResult;

interface SessionRepairServiceInterface
{
    public function integrityRefusal(string $runId): ?RepairResult;

    /** @param list<object> $postCommitActions */
    public function repair(string $runId, bool $apply, string $commandId, array $postCommitActions = []): RepairResult;
}
