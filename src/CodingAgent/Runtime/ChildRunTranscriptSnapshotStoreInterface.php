<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;

/**
 * Shared disposable child-run transcript snapshot for live-view entry.
 *
 * Ordinary poll paths must not call this. Cold initialize publishes once;
 * later enters reuse or advance from a physical suffix only.
 */
interface ChildRunTranscriptSnapshotStoreInterface
{
    public function get(string $runId): ChildRunTranscriptSnapshotDTO;

    public function find(string $runId): ?ChildRunTranscriptSnapshotDTO;

    public function remember(string $runId, ChildRunTranscriptSnapshotDTO $snapshot): void;
}
