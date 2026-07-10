<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Atomic per-run monotonic event position allocator.
 *
 * {@see allocateNext()} returns gap-allowed cursor values. JSONL must not be
 * scanned on every allocation; optional bootstrap runs only when a run has no
 * DB row yet.
 */
interface RunSequenceAllocatorInterface
{
    /**
     * Allocate the next monotonic sequence number for $runId.
     *
     * @param callable(): int|null $bootstrapMaxSeq Invoked at most once per run
     *                                              when no DB row exists yet.
     *                                              Returns max seq already present
     *                                              in the durable event log (0 when empty).
     */
    public function allocateNext(string $runId, ?callable $bootstrapMaxSeq = null): int;

    /**
     * Allocate a contiguous block of $count sequence numbers.
     *
     * @param callable(): int|null $bootstrapMaxSeq same semantics as allocateNext()
     *
     * @return list<int> ascending allocated seq values
     */
    public function allocateBlock(string $runId, int $count, ?callable $bootstrapMaxSeq = null): array;
}
