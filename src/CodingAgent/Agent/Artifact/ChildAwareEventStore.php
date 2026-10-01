<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\ChildRunPhysicalSuffixReaderInterface;

/**
 * Child-aware decorator for EventStoreInterface that delegates between parent-scoped and
 * child-scoped stores transparently.
 *
 * For parent (top-level) run IDs, delegates to SessionRunEventStore.
 * For child agent run IDs, creates per-instance AgentChildRunEventStore
 * and delegates to it.
 *
 * Child run location uses AgentChildRunDirectory.
 */
final class ChildAwareEventStore implements EventStoreInterface, ChildRunPhysicalSuffixReaderInterface
{
    /** @var array<string, AgentChildRunEventStore> agentRunId → store */
    private array $childStores = [];

    public function __construct(
        private readonly EventStoreInterface $parentStore,
        private readonly AgentChildRunEventStoreFactory $childStoreFactory,
        private readonly AgentChildRunDirectory $childRunDirectory,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        $childStore = $this->resolveChildStore($event->runId);
        if (null !== $childStore) {
            return $childStore->append($event);
        }

        return $this->parentStore->append($event);
    }

    public function appendMany(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $runId = $events[0]->runId;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \InvalidArgumentException('appendMany requires all events to share the same runId.');
            }
        }

        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->appendMany($events);
        }

        return $this->parentStore->appendMany($events);
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->latestSequenceFor($runId);
        }

        return $this->parentStore->latestSequenceFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->rangeFor($runId, $startSeq, $endSeq);
        }

        return $this->parentStore->rangeFor($runId, $startSeq, $endSeq);
    }

    /**
     * Physical reverse-cursor suffix read. Child runs use the child store cursor;
     * parent runs use the parent store reverse scan and stop at $cursor.
     *
     * @return list<RunEvent>
     */
    public function readAfterSeq(string $runId, int $cursor): array
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->readAfterSeq($runId, $cursor);
        }

        return $this->parentStore->readAfterSeq($runId, $cursor);
    }

    /**
     * Resolve a child store for the given agentRunId, or null when the
     * run is not a known child run.
     */
    private function resolveChildStore(string $runId): ?AgentChildRunEventStore
    {
        if (isset($this->childStores[$runId])) {
            return $this->childStores[$runId];
        }

        $entry = $this->childRunDirectory->locate($runId);
        if (null === $entry) {
            return null;
        }

        $store = $this->childStoreFactory->create(
            parentRunId: $entry->parentRunId,
            agentRunId: $entry->agentRunId,
            artifactId: $entry->artifactId,
        );

        $this->childStores[$runId] = $store;

        return $store;
    }
}
