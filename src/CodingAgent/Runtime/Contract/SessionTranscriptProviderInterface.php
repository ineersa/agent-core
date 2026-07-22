<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Projects transcript and active-path replay events for a session leaf.
 *
 * TUI consumes projected transcript blocks directly and replays returned runtime
 * events through TuiRuntimeEventApplier for non-transcript state (usage, queues,
 * activity). Raw active-path event filtering stays inside the app session layer.
 */
interface SessionTranscriptProviderInterface
{
    public function transcriptForLeaf(string $runId, int $leafTurnNo): SessionTranscriptSnapshotDTO;
}
