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
     * Streams a FIFO snapshot bounded by the enqueue cutoff captured at call time.
     * Stopping iteration leaves every unconsumed obligation pending.
     *
     * @return iterable<PendingCommand>
     */
    public function pending(string $runId): iterable;

    public function countPending(string $runId): int;

    /** Internal delivery obligations do not consume human-command admission capacity. */
    public function countPendingExternal(string $runId): int;

    public function markApplied(string $runId, string $idempotencyKey): void;

    public function markRejected(string $runId, string $idempotencyKey, string $reason): void;
}
