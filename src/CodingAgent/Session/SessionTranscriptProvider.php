<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\Replay\TurnTreeReplayFilter;

/**
 * Branch-aware transcript projection for a target leaf turn.
 *
 * Uses an isolated TranscriptProjector instance (not the TUI live projector).
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

    public function transcriptBlocksForLeaf(string $runId, int $leafTurnNo): array
    {
        $events = $this->eventStore->allFor($runId);

        if ([] === $events) {
            return [];
        }

        $replayDto = $this->replayFilter->filterForLeaf($runId, $events, $leafTurnNo);

        $this->transcriptProjector->reset();

        foreach ($replayDto->events as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }

            $this->transcriptProjector->accept($runtimeEvent->toArray());
        }

        return $this->transcriptProjector->blocks();
    }
}
