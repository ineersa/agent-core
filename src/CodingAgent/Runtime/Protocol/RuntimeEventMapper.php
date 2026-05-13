<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Maps agent-core RunEvent domain events to runtime protocol RuntimeEvent DTOs.
 *
 * This is the sole bridge between agent-core event types and the runtime protocol.
 * TUI code must never import RunEvent directly.
 */
final class RuntimeEventMapper
{
    /**
     * Convert a single RunEvent to a RuntimeEvent.
     *
     * Protocol type is derived from the RunEvent type.
     * Payload is forwarded as-is.
     */
    public function toRuntimeEvent(RunEvent $runEvent): RuntimeEvent
    {
        return new RuntimeEvent(
            type: $runEvent->type,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $runEvent->payload,
        );
    }

    /**
     * Convert a RuntimeEvent back to a RunEvent shape (read-only).
     *
     * @return array{runId: string, seq: int, turnNo: int, type: string, payload: array<string, mixed>}
     */
    public function toRunEventData(RuntimeEvent $event): array
    {
        return [
            'runId' => $event->runId,
            'seq' => $event->seq,
            'turnNo' => 0,
            'type' => $event->type,
            'payload' => $event->payload,
        ];
    }
}
