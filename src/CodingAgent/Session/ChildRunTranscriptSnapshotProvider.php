<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

/**
 * Full-stream child run replay projection using an isolated TranscriptProjector instance.
 *
 * Does not apply turn-tree leaf filtering (unlike SessionTranscriptProvider).
 */
final readonly class ChildRunTranscriptSnapshotProvider implements ChildRunTranscriptSnapshotProviderInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private RuntimeEventMapper $eventMapper,
        private TranscriptProjectorInterface $transcriptProjector,
    ) {
    }

    public function snapshot(string $runId): ChildRunTranscriptSnapshotDTO
    {
        $runEvents = $this->eventStore->allFor($runId);

        if ([] === $runEvents) {
            return new ChildRunTranscriptSnapshotDTO([], [], 0);
        }

        $replayEvents = [];
        $maxSeq = 0;

        foreach ($runEvents as $runEvent) {
            $runtimeEvent = $this->eventMapper->toRuntimeEvent($runEvent);
            if (null === $runtimeEvent) {
                continue;
            }

            $replayEvents[] = $runtimeEvent;

            if ($runtimeEvent->seq > 0 && $runtimeEvent->seq > $maxSeq) {
                $maxSeq = $runtimeEvent->seq;
            }
        }

        $this->transcriptProjector->reset();

        foreach ($replayEvents as $runtimeEvent) {
            $this->transcriptProjector->accept($runtimeEvent->toArray());
        }

        return new ChildRunTranscriptSnapshotDTO(
            $this->transcriptProjector->blocks(),
            $replayEvents,
            $maxSeq,
        );
    }
}
