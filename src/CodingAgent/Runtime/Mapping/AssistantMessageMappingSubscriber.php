<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Mapping;

use Ineersa\CodingAgent\Runtime\Protocol\RunEventMappingEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps AgentCore LLM step result events to runtime assistant events.
 *
 * Handles:
 *   llm_step_completed → assistant.message_completed
 *   llm_step_failed    → assistant.message_failed
 *   llm_step_aborted   → turn.cancelled
 *
 * Assistant text extraction uses the explicit 'text' payload key when
 * available (injected by LlmStepResultHandler via AssistantMessage::asText()).
 * Falls back to walking the normalized assistant_message content array
 * for backward compatibility with older events.
 */
final readonly class AssistantMessageMappingSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'llm_step_completed' => 'onLlmStepCompleted',
            'llm_step_failed' => 'onLlmStepFailed',
            'llm_step_aborted' => 'onLlmStepAborted',
        ];
    }

    public function onLlmStepCompleted(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        // Prefer the explicit text key when available (source-side extraction
        // via AssistantMessage::asText()).  Fall back to walking the
        // normalized assistant_message content array for backward compat.
        $text = \is_string($p['text'] ?? null) && '' !== $p['text']
            ? $p['text']
            : $this->extractAssistantText($p['assistant_message'] ?? null);

        $payload = [
            'message_id' => (string) ($p['step_id'] ?? ''),
            'text' => $text,
            'stop_reason' => (string) ($p['stop_reason'] ?? ''),
        ];

        if (isset($p['usage'])) {
            $payload['usage'] = $p['usage'];
        }

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageCompleted->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: $payload,
        );
    }

    public function onLlmStepFailed(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;
        $error = $p['error'] ?? [];
        $errorText = \is_array($error) && isset($error['message'])
            ? (string) $error['message']
            : 'LLM step failed';

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::AssistantMessageFailed->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: [
                'message_id' => (string) ($p['step_id'] ?? ''),
                'text' => $errorText,
                'stop_reason' => 'error',
            ],
        );
    }

    public function onLlmStepAborted(RunEventMappingEvent $event): void
    {
        if ($event->handled) {
            return;
        }

        $event->handled = true;
        $runEvent = $event->runEvent;
        $p = $runEvent->payload;

        $event->mappedRuntimeEvent = new RuntimeEvent(
            type: RuntimeEventTypeEnum::TurnCancelled->value,
            runId: $runEvent->runId,
            seq: $runEvent->seq,
            payload: ['reason' => (string) ($p['stop_reason'] ?? 'aborted')],
        );
    }

    /**
     * Legacy fallback: extract text from the normalized assistant_message
     * payload array produced by AgentMessageNormalizer::assistantMessagePayload().
     *
     * @param mixed $assistantMessage Typically array{content: list<array{type: string, text: string}>}
     */
    private function extractAssistantText(mixed $assistantMessage): string
    {
        if (!\is_array($assistantMessage)) {
            return '';
        }

        $content = $assistantMessage['content'] ?? null;
        if (!\is_array($content) || [] === $content) {
            return '';
        }

        $parts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && ('text' === ($block['type'] ?? null))) {
                $parts[] = (string) $block['text'];
            }
        }

        return [] !== $parts ? implode('', $parts) : '';
    }
}
