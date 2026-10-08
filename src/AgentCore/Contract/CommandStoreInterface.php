<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Command\PendingCommand;

interface CommandStoreInterface
{
    public function enqueue(PendingCommand $command): bool;

    /** Prepare serialization before joining the owner's metadata transaction.
     * @return \Closure(): bool
     */
    public function prepareEnqueue(PendingCommand $command): \Closure;

    public function has(string $runId, string $idempotencyKey): bool;

    /**
     * Retrieves all pending commands for a specific run ID.
     *
     * @return list<PendingCommand>
     */
    public function pending(string $runId): array;

    public function countPending(string $runId): int;

    public function markApplied(string $runId, string $idempotencyKey): void;

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void;
}
