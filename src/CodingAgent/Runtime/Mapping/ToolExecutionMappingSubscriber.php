<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore tool execution events to runtime tool events.
 *
 * Handles:
 *   tool_execution_start → tool_execution.started
 *   tool_execution_end   → tool_execution.completed or .failed
 */
final readonly class ToolExecutionMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'tool_execution_start' => 'onToolExecutionStarted',
            'tool_execution_end' => 'onToolExecutionEnded',
        ];
    }

    public function onToolExecutionStarted(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionStarted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
                'tool_name' => (string) ($p['tool_name'] ?? ''),
                'order_index' => (int) ($p['order_index'] ?? 0),
            ],
        );
    }

    public function onToolExecutionEnded(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;
        $isError = (bool) ($p['is_error'] ?? false);

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: $isError
                ? RuntimeEventTypeEnum::ToolExecutionFailed->value
                : RuntimeEventTypeEnum::ToolExecutionCompleted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'tool_call_id' => (string) ($p['tool_call_id'] ?? ''),
                'is_error' => $isError,
                'order_index' => (int) ($p['order_index'] ?? 0),
            ],
        );
    }
}
