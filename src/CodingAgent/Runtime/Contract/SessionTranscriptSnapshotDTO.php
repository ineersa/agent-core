<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Projected transcript plus compact non-transcript resume fields.
 *
 * Transcript blocks are pre-projected for display assignment. Resume projection
 * carries usage, queued messages, and subagent catalog inputs for
 * SessionInitializer without retaining mapped RuntimeEvent history.
 */
final readonly class SessionTranscriptSnapshotDTO
{
    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     */
    public function __construct(
        public array $transcriptBlocks,
        public SessionResumeProjectionDTO $resume = new SessionResumeProjectionDTO(),
    ) {
    }
}
