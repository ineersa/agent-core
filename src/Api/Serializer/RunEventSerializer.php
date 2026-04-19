<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Serializer;

use Ineersa\AgentCore\Api\Dto\RunStreamEvent;
use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * The RunEventSerializer converts between domain RunEvent and RunStreamEvent objects and their array representations for API transport. It provides bidirectional normalization to support serialization boundaries without leaking domain structure.
 */
final readonly class RunEventSerializer
{
    /**
     * Converts a RunEvent domain object into a normalized array payload.
     *
     * @return array<string, mixed>
     */
    public function normalizeRunEvent(RunEvent $event): array
    {
        return $this->normalizeStreamEvent($this->fromRunEvent($event));
    }

    /**
     * Transforms a RunEvent domain object into a RunStreamEvent instance.
     */
    public function fromRunEvent(RunEvent $event): RunStreamEvent
    {
        return new RunStreamEvent(
            runId: $event->runId,
            seq: $event->seq,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $event->payload,
            ts: $event->createdAt,
        );
    }

    /**
     * Converts a RunStreamEvent object into a normalized array payload.
     *
     * @return array<string, mixed>
     */
    public function normalizeStreamEvent(RunStreamEvent $event): array
    {
        return [
            'run_id' => $event->runId,
            'seq' => $event->seq,
            'turn_no' => $event->turnNo,
            'type' => $event->type,
            'payload' => $event->payload,
            'ts' => $event->ts->format(\DATE_ATOM),
        ];
    }
}
