<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Command\PendingCommand;

/**
 * Defines the contract for persisting and managing the lifecycle of pending commands within a specific run context. It provides mechanisms to enqueue new commands, query their status, and transition them through applied, rejected, or superseded states. This interface abstracts the underlying storage mechanism for command durability and idempotency checks.
 */
interface CommandStoreInterface
{
    public function enqueue(PendingCommand $command): bool;

    public function has(string $runId, string $idempotencyKey): bool;

    /**
     * Retrieves all pending commands for a specific run ID.
     *
     * @return list<PendingCommand>
     */
    public function pending(string $runId): array;

    public function countPending(string $runId): int;

    /**
     * Marks pending commands of a specific kind as rejected with a reason.
     *
     * @return list<PendingCommand>
     */
    public function rejectPendingByKind(string $runId, string $kind, string $reason): array;

    public function markApplied(string $runId, string $idempotencyKey): void;

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void;

    public function markSuperseded(string $runId, string $idempotencyKey, string $reason): void;
}
