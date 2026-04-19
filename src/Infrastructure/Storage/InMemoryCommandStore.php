<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;

/**
 * InMemoryCommandStore provides an in-memory implementation of the command persistence interface, managing the lifecycle of pending commands for specific run IDs. It supports idempotency checks and state transitions such as applying, rejecting, or superseding commands within a single process context.
 */
final class InMemoryCommandStore implements CommandStoreInterface
{
    /** @var array<string, array<string, PendingCommand>> */
    private array $commandsByRun = [];

    /** @var array<string, array<string, string>> */
    private array $statusesByRun = [];

    /** @var array<string, list<string>> */
    private array $orderByRun = [];

    /**
     * Adds a pending command to the store and returns success status.
     */
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

    /**
     * Checks if a command with the given run ID and idempotency key exists.
     */
    public function has(string $runId, string $idempotencyKey): bool
    {
        return isset($this->statusesByRun[$runId][$idempotencyKey]);
    }

    /**
     * Retrieves all pending commands for a specific run ID.
     */
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

    /**
     * Returns the number of pending commands for a specific run ID.
     */
    public function countPending(string $runId): int
    {
        return \count($this->pending($runId));
    }

    /**
     * Rejects all pending commands of a specific kind for a run ID.
     */
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

    /**
     * Marks a command as applied using its run ID and idempotency key.
     */
    public function markApplied(string $runId, string $idempotencyKey): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'applied';
    }

    /**
     * Marks a command as rejected with a reason using its run ID and idempotency key.
     */
    public function markRejected(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'rejected: '.$reason;
    }

    /**
     * Marks a command as superseded with a reason using its run ID and idempotency key.
     */
    public function markSuperseded(string $runId, string $idempotencyKey, string $reason): void
    {
        $this->statusesByRun[$runId][$idempotencyKey] = 'superseded: '.$reason;
    }
}
