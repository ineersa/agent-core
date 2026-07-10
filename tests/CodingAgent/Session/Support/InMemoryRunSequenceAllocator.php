<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Support;

use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;

final class InMemoryRunSequenceAllocator implements RunSequenceAllocatorInterface
{
    /** @var array<string, int> */
    private array $lastSeqByCounterPath = [];

    public function allocateNext(string $counterPath, ?callable $bootstrapMaxSeq = null): int
    {
        return $this->allocateBlock($counterPath, 1, $bootstrapMaxSeq)[0];
    }

    public function allocateBlock(string $counterPath, int $count, ?callable $bootstrapMaxSeq = null): array
    {
        if (!isset($this->lastSeqByCounterPath[$counterPath])) {
            $this->lastSeqByCounterPath[$counterPath] = null !== $bootstrapMaxSeq ? max(0, (int) $bootstrapMaxSeq()) : 0;
        }

        $start = $this->lastSeqByCounterPath[$counterPath] + 1;
        $end = $this->lastSeqByCounterPath[$counterPath] + $count;
        $this->lastSeqByCounterPath[$counterPath] = $end;

        return range($start, $end);
    }
}
