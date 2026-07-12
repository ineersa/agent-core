<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Child-aware decorator for EventStoreInterface that delegates between parent-scoped and
 * child-scoped stores transparently.
 *
 * For parent (top-level) run IDs, delegates to SessionRunEventStore.
 * For child agent run IDs, creates per-instance AgentChildRunEventStore
 * and delegates to it.
 *
 * Child run location uses the same AgentChildRunDirectory cache as
 * {@see ChildAwareRunStore}.
 */
final class ChildAwareEventStore implements SequencedEventStoreInterface
{
    /** @var array<string, AgentChildRunEventStore> agentRunId → store */
    private array $childStores = [];

    public function __construct(
        private readonly SequencedEventStoreInterface $parentStore,
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

    public function appendWithNextSeq(RunEvent $event): RunEvent
    {
        $childStore = $this->resolveChildStore($event->runId);
        if (null !== $childStore) {
            return $childStore->appendWithNextSeq($event);
        }

        return $this->parentStore->appendWithNextSeq($event);
    }

    public function appendManyWithNextSeq(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $runId = $events[0]->runId;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \InvalidArgumentException('appendManyWithNextSeq requires all events to share the same runId.');
            }
        }

        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            return $childStore->appendManyWithNextSeq($events);
        }

        return $this->parentStore->appendManyWithNextSeq($events);
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
