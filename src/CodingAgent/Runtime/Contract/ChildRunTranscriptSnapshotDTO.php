<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * One-time canonical replay snapshot for a child run (full event stream, no turn-tree leaf filter).
 */
final readonly class ChildRunTranscriptSnapshotDTO
{
    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     * @param list<RuntimeEvent>    $replayEvents     Mapped runtime events in canonical store order
     */
    public function __construct(
        public array $transcriptBlocks,
        public array $replayEvents,
        public int $maxSeq,
    ) {
    }
}
