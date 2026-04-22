<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Stores run events in memory, supporting append and retrieval of the full event history per run.
 */
final class RunEventStore implements EventStoreInterface
{
    /** @var array<string, list<RunEvent>> */
    private array $eventsByRun = [];

    public function append(RunEvent $event): void
    {
        $this->eventsByRun[$event->runId] ??= [];
        $this->eventsByRun[$event->runId][] = $event;
    }

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
