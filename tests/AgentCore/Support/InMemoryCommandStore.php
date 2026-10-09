<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;

final class InMemoryCommandStore implements CommandStoreInterface
{
    /** @var array<string, array<string, PendingCommand>> */
    private array $commandsByRun = [];

    public function enqueue(PendingCommand $command): bool
    {
        if ($this->has($command->runId, $command->idempotencyKey)) {
            return false;
        }
        $this->commandsByRun[$command->runId][$command->idempotencyKey] = $command;

        return true;
    }

    public function prepareEnqueue(PendingCommand $command): \Closure
    {
        return fn (): bool => $this->enqueue($command);
    }

    public function has(string $runId, string $idempotencyKey): bool
    {
        return isset($this->commandsByRun[$runId][$idempotencyKey]);
    }

    public function pending(string $runId): array
    {
        return array_values($this->commandsByRun[$runId] ?? []);
    }

    public function countPending(string $runId): int
    {
        return \count($this->commandsByRun[$runId] ?? []);
    }

    public function markApplied(string $runId, string $idempotencyKey): void
    {
        unset($this->commandsByRun[$runId][$idempotencyKey]);
    }

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        unset($this->commandsByRun[$runId][$idempotencyKey]);
    }
}
