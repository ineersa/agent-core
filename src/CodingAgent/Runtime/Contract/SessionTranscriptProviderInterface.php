<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Projects transcript and compact resume fields for a session position.
 *
 * TUI consumes projected transcript blocks and SessionResumeProjectionDTO.
 * Raw retained-history filtering stays inside the app session layer.
 */
interface SessionTranscriptProviderInterface
{
    public function transcriptAtPosition(string $runId, int $positionTurnNo): SessionTranscriptSnapshotDTO;
}
