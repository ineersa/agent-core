<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * One-time child-run projection for live view entry.
 *
 * Projects the full child stream (no retained-history filter). Does not retain
 * raw/mapped event history; unresolved HITL overlays are bounded separately.
 */
final readonly class ChildRunTranscriptSnapshotDTO
{
    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     * @param list<RuntimeEvent>    $pendingHumanInputEvents   unresolved human_input.requested overlays
     * @param list<RuntimeEvent>    $pendingToolQuestionEvents local tool-question overlays (seq=0)
     */
    public function __construct(
        public array $transcriptBlocks,
        public SessionResumeProjectionDTO $resume,
        public array $pendingHumanInputEvents,
        public array $pendingToolQuestionEvents,
        public int $maxSeq,
    ) {
    }
}
