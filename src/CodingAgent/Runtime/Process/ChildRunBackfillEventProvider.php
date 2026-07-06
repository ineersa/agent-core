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
 * One-shot resume backfill guard: the first call for a run ID loads all stored
 * events and marks the run as backfilled.  Subsequent calls return empty without
 * re-reading storage.  This is intentional:
 *  - Resumed children need their stored events projected exactly once.
 *  - Active child runs stream new events through the stdout path (ConsumerStdoutPoller),
 *    so repeated file reads are unnecessary.
 *  - Retrying empty/unknown child stores every poll tick would reintroduce the
 *    hot file polling that the stdout streaming pivot eliminated.
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

        // Mark before read: one-shot resume backfill.  Even if the store returns
        // empty (unknown run), we do not retry on subsequent poll ticks — the
        // active child stdout stream will cover new events for known runs, and
        // retrying empty stores would reintroduce the hot file polling that the
        // stdout streaming pivot eliminated.
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
}
