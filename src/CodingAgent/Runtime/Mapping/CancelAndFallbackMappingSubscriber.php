<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore cancel commands, internal bookkeeping events,
 * and fallback status updates to runtime protocol events.
 *
 * Handles:
 *   agent_command_applied (cancel kind) → cancellation.requested
 *   agent_command_applied (other) → status.updated
 *   agent_command_rejected → status.updated
 *   stale_result_ignored → status.updated
 *   tool_call_result_received → dropped (null)
 *   tool_batch_committed → dropped (null)
 *   agent_command_queued → dropped (null)
 *   agent_command_superseded → dropped (null)
 */
final readonly class CancelAndFallbackMappingSubscriber implements EventSubscriberInterface
{
    private const string DEBUG_RAW_TYPE = 'debug.raw_type';
    private const string DEBUG_RAW_PAYLOAD = 'debug.raw_payload';

    public static function getSubscribedEvents(): array
    {
        return [
            'agent_command_applied' => 'onAgentCommandApplied',
            'agent_command_rejected' => 'onStatusUpdated',
            'stale_result_ignored' => 'onStatusUpdated',
            'tool_call_result_received' => 'onDrop',
            'tool_batch_committed' => 'onDrop',
            'agent_command_queued' => 'onDrop',
            'agent_command_superseded' => 'onDrop',
        ];
    }

    public function onAgentCommandApplied(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;
        $kind = (string) ($p['kind'] ?? '');

        if ('cancel' === $kind) {
            $event->mappedRuntimeEvent = new RuntimeEvent(
                type: RuntimeEventTypeEnum::CancellationRequested->value,
                runId: $runEvent->runId,
                seq: $runEvent->seq,
                payload: ['kind' => $kind, 'reason' => 'user_cancelled'],
            );
        } else {
            $event->mappedRuntimeEvent = $this->statusUpdatedEvent($runEvent);
        }
    }

    public function onStatusUpdated(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $event->mappedRuntimeEvent = $this->statusUpdatedEvent($event->runEvent);
    }

    public function onDrop(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        // Mark as handled so the facade does not treat this as unknown.
        // mappedRuntimeEvent remains null → dropped from the stream.
        $event->handled = true;
    }

    private function statusUpdatedEvent(\Ineersa\AgentCore\Domain\Event\RunEvent $runEvent): RuntimeEvent
    {
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
}
