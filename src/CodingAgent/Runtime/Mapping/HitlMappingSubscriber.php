<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore HITL event (waiting_human) to runtime human_input.requested.
 */
final readonly class HitlMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'waiting_human' => 'onWaitingHuman',
        ];
    }

    public function onWaitingHuman(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        $payload = [
            'question_id' => (string) ($p['question_id'] ?? ''),
            'prompt' => (string) ($p['prompt'] ?? 'Human input required.'),
        ];

        if (isset($p['schema'])) {
            $payload['schema'] = $p['schema'];
        }
        if (isset($p['tool_call_id'])) {
            $payload['tool_call_id'] = $p['tool_call_id'];
        }
        if (isset($p['tool_name'])) {
            $payload['tool_name'] = $p['tool_name'];
        }

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }
}
