<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore HITL events to runtime protocol events.
 *
 * Handles:
 *   waiting_human → human_input.requested (existing)
 *   agent_command_applied (kind=human_response) → human_input.answered (new)
 *
 * The agent_command_applied handler must run BEFORE
 * CancelAndFallbackMappingSubscriber, which maps all non-cancel
 * agent_command_applied events to status.updated.
 * Priority 10 ensures we handle human_response before
 * CancelAndFallbackMappingSubscriber (priority 0).
 */
final readonly class HitlMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'waiting_human' => 'onWaitingHuman',
            'agent_command_applied' => ['onAgentCommandApplied', 10],
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

    public function onAgentCommandApplied(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $p = $event->runEvent->payload;
        $kind = (string) ($p['kind'] ?? '');

        if ('human_response' !== $kind) {
            return;
        }

        $event->handled = true;

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputAnswered->value,
            runId: $event->runEvent->runId,
            seq: $event->runEvent->seq,
            payload: [
                'question_id' => (string) ($p['question_id'] ?? ''),
                'answer' => (string) ($p['answer'] ?? ''),
            ],
        );
    }
}
