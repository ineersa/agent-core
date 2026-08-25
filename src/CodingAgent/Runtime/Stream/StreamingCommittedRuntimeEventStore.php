<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;

final class StreamingCommittedRuntimeEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly RuntimeEventMapper $mapper,
        private readonly RuntimeEventSinkInterface $stdoutSink,
        private readonly bool $streamCommittedEventsToStdout,
    ) {
    }

    public function append(RunEvent $event): RunEvent
    {
        $persisted = $this->inner->append($event);
        $this->emitMapped($persisted);

        return $persisted;
    }

    public function appendMany(array $events): array
    {
        $persisted = $this->inner->appendMany($events);
        foreach ($persisted as $event) {
            $this->emitMapped($event);
        }

        return $persisted;
    }

    public function latestSequenceFor(string $runId): ?int
    {
        return $this->inner->latestSequenceFor($runId);
    }

    public function firstFor(string $runId): ?RunEvent
    {
        return $this->inner->firstFor($runId);
    }

    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        return $this->inner->rangeFor($runId, $startSeq, $endSeq);
    }

    public function reverseFor(string $runId): iterable
    {
        return $this->inner->reverseFor($runId);
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
