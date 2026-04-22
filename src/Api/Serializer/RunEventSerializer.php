<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Serializer;

use Ineersa\AgentCore\Api\Dto\RunStreamEvent;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;

final readonly class RunEventSerializer
{
    private EventPayloadNormalizer $eventPayloadNormalizer;

    public function __construct(?EventPayloadNormalizer $eventPayloadNormalizer = null)
    {
        $this->eventPayloadNormalizer = $eventPayloadNormalizer ?? new EventPayloadNormalizer();
    }

    /**
     * Converts a RunEvent domain object into a normalized array payload.
     *
     * @return array<string, mixed>
     */
    public function normalizeRunEvent(RunEvent $event): array
    {
        return $this->eventPayloadNormalizer->normalizeRunEvent($event);
    }

    public function fromRunEvent(RunEvent $event): RunStreamEvent
    {
        return new RunStreamEvent(
            runId: $event->runId,
            seq: $event->seq,
            turnNo: $event->turnNo,
            type: $this->eventPayloadNormalizer->toPublicType($event->type),
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
        return $this->eventPayloadNormalizer->normalize(
            runId: $event->runId,
            seq: $event->seq,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $event->payload,
            ts: $event->ts,
        );
    }
}
