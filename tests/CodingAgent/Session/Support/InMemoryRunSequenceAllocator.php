<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Support;

use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;

final class InMemoryRunSequenceAllocator implements RunSequenceAllocatorInterface
{
    /** @var array<string, int> */
    private array $lastSeqByRun = [];

    public function allocateNext(string $runId, ?callable $bootstrapMaxSeq = null): int
    {
        return $this->allocateBlock($runId, 1, $bootstrapMaxSeq)[0];
    }

    public function allocateBlock(string $runId, int $count, ?callable $bootstrapMaxSeq = null): array
    {
        if (!isset($this->lastSeqByRun[$runId])) {
            $this->lastSeqByRun[$runId] = null !== $bootstrapMaxSeq ? max(0, (int) $bootstrapMaxSeq()) : 0;
        }

        $start = $this->lastSeqByRun[$runId] + 1;
        $end = $this->lastSeqByRun[$runId] + $count;
        $this->lastSeqByRun[$runId] = $end;

        return range($start, $end);
    }
}
