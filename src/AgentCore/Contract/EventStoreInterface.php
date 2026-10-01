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
     * Streams canonical events with sequence in the inclusive [startSeq, endSeq] range,
     * in durable append order. Invalid or empty ranges and unknown runs yield no events.
     *
     * @return iterable<RunEvent>
     */
    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable;

    /**
     * Returns events with sequence strictly greater than $cursor in ascending order,
     * using a physical reverse-cursor scan that stops once the cursor is reached.
     *
     * @return list<RunEvent>
     */
    public function readAfterSeq(string $runId, int $cursor): array;
}
