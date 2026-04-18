<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;

final class InMemoryCommandStore implements CommandStoreInterface
{
    /** @var array<string, array<string, string>> */
    private array $statuses = [];

    /** @var list<PendingCommand> */
    private array $pending = [];

    public function enqueue(PendingCommand $command): void
    {
        $this->pending[] = $command;
        $this->statuses[$command->runId][$command->idempotencyKey] = 'pending';
    }

    public function markApplied(string $runId, string $idempotencyKey): void
    {
        $this->statuses[$runId][$idempotencyKey] = 'applied';
    }

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->statuses[$runId][$idempotencyKey] = 'rejected: '.$reason;
    }
}
