<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Contract\CommittedEventStoreInterface;

final class InMemoryEventStore implements CommittedEventStoreInterface
{
    /** @var array<string, list<RunEvent>> */
    private array $eventsByRun = [];

    /** @var array<string, int> */
    private array $highWaterByRun = [];


    /**
     * Test-only: insert a persisted row with explicit seq (gaps/historical logs).
     */
    public function seed(RunEvent $event): void
    {
        $this->eventsByRun[$event->runId] ??= [];
        $this->eventsByRun[$event->runId][] = $event;
        $this->highWaterByRun[$event->runId] = max($this->highWaterByRun[$event->runId] ?? 0, $event->seq);
    }

    public function append(RunEvent $event): RunEvent
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
        $this->eventsByRun[$event->runId] ??= [];
        $this->eventsByRun[$event->runId][] = $persisted;

        return $persisted;
    }

    public function appendMany(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = $this->append($event);
        }

        return $out;
    }

    public function allFor(string $runId): array
    {
        $events = $this->eventsByRun[$runId] ?? [];
        usort($events, static fn (RunEvent $l, RunEvent $r): int => $l->seq <=> $r->seq);

        return $events;
    }
}
