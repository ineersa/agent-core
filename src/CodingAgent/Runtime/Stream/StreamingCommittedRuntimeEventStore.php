<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

/**
 * Decorates EventStoreInterface to stream mapped RuntimeEvents to stdout after durable append.
 *
 * Live transport path: messenger consumer persists RunEvent → this decorator emits JSONL on
 * stdout → controller ConsumerStdoutPoller → TUI. events.jsonl remains recovery/replay only.
 */
final class StreamingCommittedRuntimeEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly RuntimeEventMapper $mapper,
        private readonly RuntimeEventSinkInterface $stdoutSink,
        private readonly bool $streamCommittedEventsToStdout,
    ) {
    }

    public function append(RunEvent $event): void
    {
        $this->inner->append($event);
        $this->emitMapped($event);
    }

    public function appendMany(array $events): void
    {
        $this->inner->appendMany($events);
        foreach ($events as $event) {
            $this->emitMapped($event);
        }
    }

    public function allFor(string $runId): array
    {
        return $this->inner->allFor($runId);
    }

    private function emitMapped(RunEvent $runEvent): void
    {
        if (!$this->streamCommittedEventsToStdout) {
            return;
        }

        $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
        if (null === $runtimeEvent) {
            return;
        }

        $this->stdoutSink->emit($runtimeEvent);
    }
}
