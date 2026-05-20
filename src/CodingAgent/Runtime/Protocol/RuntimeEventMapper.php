<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Normalizes agent-core RunEvent domain events into stable runtime protocol RuntimeEvent DTOs.
 *
 * This is the sole bridge between agent-core event types and the runtime protocol.
 * TUI code must never import RunEvent or AgentCore internals directly.
 *
 * The mapper dispatches each RunEvent through a Symfony EventDispatcher:
 * family-specific subscribers (in Runtime\Mapping\) handle known event types
 * while internal bookkeeping events are dropped and unknown events fall back
 * to status.updated with debug metadata.
 */
final class RuntimeEventMapper
{
    private const string DEBUG_RAW_TYPE = 'debug.raw_type';
    private const string DEBUG_RAW_PAYLOAD = 'debug.raw_payload';

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher(),
    ) {
    }

    /**
     * Convert a single RunEvent to a RuntimeEvent with normalized type and payload.
     *
     * Returns null when the AgentCore event should not appear in the runtime
     * stream (e.g. internal bookkeeping events like tool_batch_committed).
     */
    public function toRuntimeEvent(RunEvent $runEvent): ?RuntimeEvent
    {
        $mappingEvent = new RunEventMappingEvent($runEvent);

        // Dispatch by raw AgentCore event type string. Subscribers handle
        // known types; unknown types fall through to the fallback below.
        $this->dispatcher->dispatch($mappingEvent, $runEvent->type);

        if ($mappingEvent->handled) {
            return $mappingEvent->mappedRuntimeEvent; // null = drop
        }

        // Unknown event type → status.updated with debug metadata.
        return new RuntimeEvent(
            type: RuntimeEventTypeEnum::StatusUpdated->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                self::DEBUG_RAW_TYPE => $runEvent->type,
                self::DEBUG_RAW_PAYLOAD => $runEvent->payload,
            ],
        );
    }

    /**
     * Convert a RuntimeEvent back to a RunEvent-like array.
     *
     * The type field carries the normalized runtime type by default.
     * Raw AgentCore type is preserved in debug metadata when available.
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
