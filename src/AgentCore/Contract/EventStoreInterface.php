<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

use Ineersa\AgentCore\Domain\Event\RunEvent;

interface EventStoreInterface
{
    public function append(RunEvent $event): RunEvent;

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendMany(array $events): array;

    /**
     * Latest durably appended canonical sequence, or null when the run has no events.
     */
    public function latestSequenceFor(string $runId): ?int;

    /**
     * First canonical event, or null when the run has no events.
     */
    public function firstFor(string $runId): ?RunEvent;

    /**
     * Streams canonical events with sequence in the inclusive [startSeq, endSeq] range,
     * in durable append order. Invalid or empty ranges and unknown runs yield no events.
     *
     * @return iterable<RunEvent>
     */
    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable;

    /**
     * Streams canonical events newest-first. Implementations may stop reading when the consumer stops.
     *
     * @return iterable<RunEvent>
     */
    public function reverseFor(string $runId): iterable;

    /**
     * Retrieves all events associated with a specific run ID.
     *
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array;
}
