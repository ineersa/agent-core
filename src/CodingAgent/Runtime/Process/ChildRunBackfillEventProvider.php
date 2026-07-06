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
 * Tracks which run IDs have been backfilled to avoid re-reading on every poll tick.
 * The first call for a run ID loads all events; subsequent calls return empty.
 */
final class ChildRunBackfillEventProvider implements BackfillEventProviderInterface
{
    /** @var array<string, true> run IDs already backfilled in this process */
    private array $backfilled = [];

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly RuntimeEventMapper $mapper,
    ) {
    }

    public function getStoredEvents(string $runId): array
    {
        if (isset($this->backfilled[$runId])) {
            return [];
        }

        $this->backfilled[$runId] = true;

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

        \usort($runtimeEvents, static fn (RuntimeEvent $a, RuntimeEvent $b): int => $a->seq <=> $b->seq);

        return $runtimeEvents;
    }

    public function markBackfilled(string $runId): void
    {
        $this->backfilled[$runId] = true;
    }
}
