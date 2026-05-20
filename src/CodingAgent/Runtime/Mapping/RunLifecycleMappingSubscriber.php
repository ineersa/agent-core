<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore lifecycle events to runtime protocol events.
 *
 * Handles: run_started → run.started, turn_advanced → turn.started,
 * agent_end → run.completed / run.cancelled / run.failed.
 */
final readonly class RunLifecycleMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'run_started' => 'onRunStarted',
            'turn_advanced' => 'onTurnStarted',
            'agent_end' => 'onAgentEnd',
        ];
    }

    public function onRunStarted(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['step_id' => (string) ($p['step_id'] ?? '')],
        );
    }

    public function onTurnStarted(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['turn_no' => (int) ($p['turn_no'] ?? 0)],
        );
    }

    public function onAgentEnd(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;
        $reason = (string) ($p['reason'] ?? '');

        $type = match ($reason) {
            'cancelled' => RuntimeEventTypeEnum::RunCancelled->value,
            'failed' => RuntimeEventTypeEnum::RunFailed->value,
            default => RuntimeEventTypeEnum::RunCompleted->value,
        };

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: $type,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['reason' => '' !== $reason ? $reason : 'completed'],
        );
    }
}
