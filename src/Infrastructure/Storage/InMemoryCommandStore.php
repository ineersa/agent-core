<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;

/**
 * Manages pending commands in memory with idempotent enqueue and state transitions (applied, rejected, superseded) per run.
 */
final class InMemoryCommandStore implements CommandStoreInterface
{
    /** @var array<string, array<string, PendingCommand>> */
    private array $commandsByRun = [];

    /** @var array<string, array<string, string>> */
    private array $statusesByRun = [];

    /** @var array<string, list<string>> */
    private array $orderByRun = [];

    public function enqueue(PendingCommand $command): bool
    {
        if ($this->has($command->runId, $command->idempotencyKey)) {
            return false;
        }

        $this->commandsByRun[$command->runId][$command->idempotencyKey] = $command;
        $this->statusesByRun[$command->runId][$command->idempotencyKey] = 'pending';
        $this->orderByRun[$command->runId][] = $command->idempotencyKey;

        return true;
    }

    public function has(string $runId, string $idempotencyKey): bool
    {
        return isset($this->statusesByRun[$runId][$idempotencyKey]);
    }

    public function pending(string $runId): array
    {
        $pending = [];

        foreach ($this->orderByRun[$runId] ?? [] as $idempotencyKey) {
            if ('pending' !== ($this->statusesByRun[$runId][$idempotencyKey] ?? null)) {
                continue;
            }

            $command = $this->commandsByRun[$runId][$idempotencyKey] ?? null;
            if (null === $command) {
                continue;
            }

            $pending[] = $command;
        }

        return $pending;
    }

    public function countPending(string $runId): int
    {
        return \count($this->pending($runId));
    }

    public function rejectPendingByKind(string $runId, string $kind, string $reason): array
    {
        $rejected = [];

        foreach ($this->pending($runId) as $command) {
            if ($command->kind !== $kind) {
                continue;
            }

            $this->markRejected($runId, $command->idempotencyKey, $reason);
            $rejected[] = $command;
        }

        return $rejected;
    }

    public function markApplied(string $runId, string $idempotencyKey): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'applied';
    }

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'rejected: '.$reason;
    }

    public function markSuperseded(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'superseded: '.$reason;
    }
}
