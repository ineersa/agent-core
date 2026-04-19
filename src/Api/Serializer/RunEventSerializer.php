<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Serializer;

use Ineersa\AgentCore\Api\Dto\RunStreamEvent;
use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class RunEventSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function normalizeRunEvent(RunEvent $event): array
    {
        return $this->normalizeStreamEvent($this->fromRunEvent($event));
    }

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
