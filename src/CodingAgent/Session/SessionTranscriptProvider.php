<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\Replay\TurnTreeReplayFilter;

/**
 * Branch-aware transcript projection for a target leaf turn.
 *
 * Uses an isolated TranscriptProjector service instance (not the TUI live projector).
 * The same projector is reset per call and is safe under sequential access from
 * SessionInitializer and RuntimeEventPoller.
 */
final readonly class SessionTranscriptProvider implements SessionTranscriptProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private TurnTreeReplayFilter $replayFilter,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
    ) {
    }

    public function transcriptForLeaf(string $runId, int $leafTurnNo): SessionTranscriptSnapshotDTO
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return new SessionTranscriptSnapshotDTO([], []);
        }

        $replayDto = $this->replayFilter->filterForLeaf($runId, $events, $leafTurnNo);

        $replayEvents = [];
        foreach ($replayDto->events as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent) {
                $replayEvents[] = $runtimeEvent;
            }
        }

        $this->transcriptProjector->reset();

        foreach ($replayEvents as $runtimeEvent) {
            $this->transcriptProjector->accept($runtimeEvent->toArray());
        }

        return new SessionTranscriptSnapshotDTO(
            $this->transcriptProjector->blocks(),
            $replayEvents,
        );
    }
}
