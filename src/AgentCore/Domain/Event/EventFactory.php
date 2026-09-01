<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

final readonly class EventFactory
{
    /**
     * @param array<string, mixed> $payload
     */
    public function event(string $runId, int $seq, int $turnNo, string $type, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }

    /**
     * @param list<array{type: string, payload: array<string, mixed>, turn_no?: int}> $eventSpecs
     *
     * @return list<RunEvent>
     */
    public function eventsFromSpecs(string $runId, int $turnNo, int $startSeq, array $eventSpecs): array
    {
        $events = [];
        $seq = $startSeq;

        foreach ($eventSpecs as $eventSpec) {
            $eventTurnNo = \is_int($eventSpec['turn_no'] ?? null)
                ? $eventSpec['turn_no']
                : $turnNo;

            $events[] = $this->event(
                runId: $runId,
                seq: $seq,
                turnNo: $eventTurnNo,
                type: $eventSpec['type'],
                payload: $eventSpec['payload'],
            );

            ++$seq;
        }

        return $events;
    }
}
