<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Session\Replay\SessionColdReconstructionService;

/**
 * History-aware transcript projection for a selected position.
 *
 * Uses the centralized cold reconstruction scan. Does not publish shared
 * RunState/history — callers that own recovery publish explicitly.
 */
final readonly class SessionTranscriptProvider implements SessionTranscriptProviderInterface
{
    public function __construct(
        private SessionColdReconstructionService $coldReconstruction,
    ) {
    }

    public function transcriptAtPosition(string $runId, int $positionTurnNo): SessionTranscriptSnapshotDTO
    {
        return $this->coldReconstruction->reconstruct(
            runId: $runId,
            positionTurnNo: $positionTurnNo,
            publishSharedState: false,
            publishHistory: false,
        )->transcript;
    }
}
