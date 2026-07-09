<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\CodingAgent\Runtime\Contract\BackfillEventProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

/**
 * Backfills stored RuntimeEvents for child run IDs from the EventStore.
 *
 * Uses the EventStoreInterface (which resolves through DI to ChildAwareEventStore)
 * to load stored RunEvents, then maps them to RuntimeEvents via RuntimeEventMapper.
 *
 * Repeated reads are expected while the selected child live view is active:
 * child artifact events are not live-forwarded on the parent stdout pipe, so
 * post-HITL completion must be picked up from durable storage.  Callers must
 * dedupe by seq ({@see SubagentLiveChildViewPoller::$childLastSeq}).  Only the
 * selected-child live poller should invoke this provider — background catalog
 * polling must not consume stored backfill.
 */
final class ChildRunBackfillEventProvider implements BackfillEventProviderInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly RuntimeEventMapper $mapper,
    ) {
    }

    public function getStoredEvents(string $runId): array
    {
        $storedEvents = $this->eventStore->allFor($runId);

        if ([] === $storedEvents) {
            return [];
        }

        $runtimeEvents = [];

        foreach ($storedEvents as $runEvent) {
            $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent && $runtimeEvent->seq > 0) {
                $runtimeEvents[] = $runtimeEvent;
            }
        }

        usort($runtimeEvents, static fn (RuntimeEvent $a, RuntimeEvent $b): int => $a->seq <=> $b->seq);

        return $runtimeEvents;
    }
}
