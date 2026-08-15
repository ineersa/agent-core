<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;

/**
 * History-aware transcript projection for a selected position.
 *
 * Uses an isolated TranscriptProjector service instance (not the TUI live projector).
 */
final readonly class SessionTranscriptProvider implements SessionTranscriptProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private HistoryReplayFilter $replayFilter,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
    ) {
    }

    public function transcriptAtPosition(string $runId, int $positionTurnNo): SessionTranscriptSnapshotDTO
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return new SessionTranscriptSnapshotDTO([], []);
        }

        // Explicit 0 = before first turn (empty retained prefix).
        $filteredEvents = $this->replayFilter->filterAtPosition($events, $positionTurnNo);

        $replayEvents = [];
        foreach ($filteredEvents as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent) {
                $replayEvents[] = $runtimeEvent;
            }
        }

        $this->transcriptProjector->reset();

        foreach ($replayEvents as $runtimeEvent) {
            $this->transcriptProjector->accept($runtimeEvent);
        }

        return new SessionTranscriptSnapshotDTO(
            $this->transcriptProjector->blocks(),
            $replayEvents,
        );
    }
}
