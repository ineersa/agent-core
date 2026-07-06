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
 * Child run location uses the same AgentChildRunDirectory cache as
 * {@see ChildAwareRunStore}.
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

    public function append(RunEvent $event): void
    {
        $runId = $event->runId;

        $childStore = $this->resolveChildStore($runId);
        if (null !== $childStore) {
            $childStore->append($event);

            return;
        }

        // Not a known child run — delegate to parent store.
        $this->parentStore->append($event);
    }

    public function appendMany(array $events): void
    {
        // Group events by whether they belong to child runs.
        $parentEvents = [];
        $childEventsByRunId = [];

        foreach ($events as $event) {
            $runId = $event->runId;
            $childStore = $this->resolveChildStore($runId);
            if (null !== $childStore) {
                $childEventsByRunId[$runId][] = $event;
            } else {
                $parentEvents[] = $event;
            }
        }

        if ([] !== $parentEvents) {
            $this->parentStore->appendMany($parentEvents);
        }

        foreach ($childEventsByRunId as $runId => $childEvents) {
            $childStore = $this->resolveChildStore($runId);
            if (null !== $childStore) {
                $childStore->appendMany($childEvents);
            }
        }
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
