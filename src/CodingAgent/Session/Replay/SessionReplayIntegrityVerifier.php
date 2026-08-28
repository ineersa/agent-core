<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Dto\ReplayIntegrity;
use Ineersa\AgentCore\Application\Dto\ResolvedReplayEvents;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\HotPromptIntegrityVerifierInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class SessionReplayIntegrityVerifier implements HotPromptIntegrityVerifierInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private ReplayEventPreparer $replayEventPreparer,
    ) {
    }

    /**
     * Validates event sequence integrity and identifies missing sequences for a run.
     */
    public function verifyIntegrity(string $runId): ReplayIntegrity
    {
        $events = $this->eventStore->allFor($runId);
        $resolvedReplayEvents = new ResolvedReplayEvents(
            events: $this->replayEventPreparer->sortBySequence($events),
            source: 'canonical_events',
        );
        $missingSequences = $this->replayEventPreparer->missingSequences($resolvedReplayEvents->events);

        return new ReplayIntegrity(
            runId: $runId,
            source: $resolvedReplayEvents->source,
            eventCount: \count($resolvedReplayEvents->events),
            lastSeq: [] === $resolvedReplayEvents->events
                ? 0
                : max(array_map(static fn (RunEvent $event): int => $event->seq, $resolvedReplayEvents->events)),
            missingSequences: $missingSequences,
            isContiguous: [] === $missingSequences,
        );
    }
}
