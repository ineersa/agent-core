<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Command\PendingCommand;

interface CommandStoreInterface
{
    public function enqueue(PendingCommand $command): void;

    public function markApplied(string $runId, string $idempotencyKey): void;

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void;
}
