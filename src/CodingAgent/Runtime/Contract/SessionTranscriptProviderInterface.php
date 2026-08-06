<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Projects transcript and retained-history replay events for a session position.
 *
 * TUI consumes projected transcript blocks directly and replays returned runtime
 * events through TuiRuntimeEventApplier for non-transcript state (usage, queues,
 * activity). Raw retained-history filtering stays inside the app session layer.
 */
interface SessionTranscriptProviderInterface
{
    public function transcriptAtPosition(string $runId, int $positionTurnNo): SessionTranscriptSnapshotDTO;
}
