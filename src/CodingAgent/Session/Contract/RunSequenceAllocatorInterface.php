<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Contract;

/**
 * Per-run monotonic event position allocator (counter file path scoped).
 *
 * Optional bootstrap runs only when the counter file is missing.
 */
interface RunSequenceAllocatorInterface
{
    /**
     * @param callable(): int|null $bootstrapMaxSeq invoked when counter file missing
     */
    public function allocateNext(string $counterPath, ?callable $bootstrapMaxSeq = null): int;

    /**
     * @param callable(): int|null $bootstrapMaxSeq invoked when counter file missing
     *
     * @return list<int>
     */
    public function allocateBlock(string $counterPath, int $count, ?callable $bootstrapMaxSeq = null): array;
}
