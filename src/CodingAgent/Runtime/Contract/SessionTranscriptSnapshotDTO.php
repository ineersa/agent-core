<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Projected transcript plus active-path runtime events for TUI resume/leaf rewind.
 *
 * Transcript blocks are pre-projected for display assignment. Replay events are
 * mapped active-path runtime events for non-transcript TUI state reconstruction
 * (usage, activity, queued messages, subagent catalog) via TuiRuntimeEventApplier.
 */
final readonly class SessionTranscriptSnapshotDTO
{
    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     * @param list<RuntimeEvent>    $replayEvents     Active-path runtime events in canonical order
     */
    public function __construct(
        public array $transcriptBlocks,
        public array $replayEvents,
    ) {
    }
}
