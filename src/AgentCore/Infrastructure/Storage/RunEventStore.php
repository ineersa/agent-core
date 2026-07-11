<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;

final class RunEventStore implements SequencedEventStoreInterface
{
    /** @var array<string, list<RunEvent>> */
    private array $eventsByRun = [];

    /** @var array<string, int> */
    private array $highWaterByRun = [];

    public function appendWithNextSeq(RunEvent $event): RunEvent
    {
        $next = ($this->highWaterByRun[$event->runId] ?? 0) + 1;
        $this->highWaterByRun[$event->runId] = $next;
        $persisted = new RunEvent(
            runId: $event->runId,
            seq: $next,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $event->payload,
            createdAt: $event->createdAt,
        );
        $this->append($persisted);

        return $persisted;
    }

    public function appendManyWithNextSeq(array $events): array
    {
        $persisted = [];
        foreach ($events as $event) {
            $persisted[] = $this->appendWithNextSeq($event);
        }

        return $persisted;
    }

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
