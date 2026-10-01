<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\CodingAgent\Runtime\ChildRunTranscriptSnapshotStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;

/** Process-local child transcript snapshot store for unit tests. */
final class InMemoryChildRunTranscriptSnapshotStore implements ChildRunTranscriptSnapshotStoreInterface
{
    public int $rememberCalls = 0;

    /** @var array<string, ChildRunTranscriptSnapshotDTO> */
    private array $snapshots = [];

    public function get(string $runId): ChildRunTranscriptSnapshotDTO
    {
        return $this->snapshots[$runId]
            ?? throw new \RuntimeException(\sprintf('Child transcript snapshot missing for run %s; initialize via child-view startup before ordinary reuse.', $runId));
    }

    public function find(string $runId): ?ChildRunTranscriptSnapshotDTO
    {
        return $this->snapshots[$runId] ?? null;
    }

    public function remember(string $runId, ChildRunTranscriptSnapshotDTO $snapshot): void
    {
        ++$this->rememberCalls;
        $cached = $this->snapshots[$runId] ?? null;
        if (null !== $cached && $cached->maxSeq > $snapshot->maxSeq) {
            return;
        }

        $this->snapshots[$runId] = $snapshot;
    }
}
