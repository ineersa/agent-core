<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/** Display blocks for explicit history selection, not owner recovery. */
final readonly class SessionTranscriptSnapshotDTO
{
    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     */
    public function __construct(
        public array $transcriptBlocks,
    ) {
    }
}
