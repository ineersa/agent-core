<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

use Ineersa\AgentCore\Domain\Run\RunState;

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

    public function incrementStateVersion(RunState $state, int $eventCount): RunState
    {
        return new RunState(
            runId: $state->runId,
            status: $state->status,
            version: $state->version + 1,
            turnNo: $state->turnNo,
            lastSeq: $state->lastSeq + $eventCount,
            isStreaming: $state->isStreaming,
            streamingMessage: $state->streamingMessage,
            pendingToolCalls: $state->pendingToolCalls,
            errorMessage: $state->errorMessage,
            messages: $state->messages,
            activeStepId: $state->activeStepId,
            retryableFailure: $state->retryableFailure,
            pendingHumanInputRequests: $state->pendingHumanInputRequests,
            model: $state->model,
        );
    }
}
