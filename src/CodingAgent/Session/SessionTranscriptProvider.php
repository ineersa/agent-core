<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator;

/** Indexed display-only history projection. Never reconstructs execution state. */
final readonly class SessionTranscriptProvider implements SessionTranscriptProviderInterface
{
    public function __construct(private SessionReplayCoordinator $coordinator)
    {
    }

    public function transcriptAtPosition(string $runId, int $positionTurnNo): SessionTranscriptSnapshotDTO
    {
        return new SessionTranscriptSnapshotDTO($this->coordinator->transcriptAtPosition($runId, $positionTurnNo));
    }
}
