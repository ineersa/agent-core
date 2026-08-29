<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

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
final class ChildAwareEventStore implements EventStoreInterface
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

    public function firstFor(string $runId): ?RunEvent
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->firstFor($runId);
        }

        return $this->parentStore->firstFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->rangeFor($runId, $startSeq, $endSeq);
        }

        return $this->parentStore->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->reverseFor($runId);
        }

        return $this->parentStore->reverseFor($runId);
    }

    public function allFor(string $runId): array
    {
        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->allFor($runId);
        }

        return $this->parentStore->allFor($runId);
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
