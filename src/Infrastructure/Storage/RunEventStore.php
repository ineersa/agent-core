<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * The RunEventStore provides a persistence layer for RunEvent entities within the infrastructure storage boundary. It abstracts the underlying storage mechanism to allow appending individual or batched events and retrieving the complete event history for a specific run. This class serves as the primary interface for run-related event data access and mutation.
 */
final class RunEventStore implements EventStoreInterface
{
    /** @var array<string, list<RunEvent>> */
    private array $eventsByRun = [];

    /**
     * persists a single RunEvent to storage.
     */
    public function append(RunEvent $event): void
    {
        $this->eventsByRun[$event->runId] ??= [];
        $this->eventsByRun[$event->runId][] = $event;
    }

    /**
     * persists an array of RunEvents to storage.
     */
    public function appendMany(array $events): void
    {
        foreach ($events as $event) {
            $this->append($event);
        }
    }

    /**
     * retrieves all RunEvents associated with a specific runId.
     *
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        $events = $this->eventsByRun[$runId] ?? [];

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }
}
