<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects user-message events into UserMessage transcript blocks.
 */
final readonly class UserMessageProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::UserMessageSubmitted->value => 'onUserMessageSubmitted',
            RuntimeEventTypeEnum::RunStarted->value => 'onRunStarted',
        ];
    }

    public function onUserMessageSubmitted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $state->addBlock(new TranscriptBlock(
            id: (string) ($p['message_id'] ?? ''),
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
        ));
    }

    /**
     * Project initial user messages from run.started payload.
     *
     * When a run starts, the normalized StartRunPayload may contain user-role
     * messages (initial prompt). These are included as user_messages in the
     * run.started runtime event payload so events.jsonl replay can produce
     * user message transcript blocks for the very first turn.
     *
     * Note: The seq assigned by $state->nextSeq() is projector-local ordering,
     * not the runtime event seq. Projector seq is deterministic for replay
     * (same input events produce same block ordering) but does not correspond
     * to any canonical event sequence number.
     */
    public function onRunStarted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $userMessages = $p['user_messages'] ?? [];
        if (!\is_array($userMessages) || [] === $userMessages) {
            return;
        }

        foreach ($userMessages as $userMsg) {
            $state->addBlock(new TranscriptBlock(
                id: (string) ($userMsg['message_id'] ?? ''),
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: $event->runId(),
                seq: $state->nextSeq(),
                text: (string) ($userMsg['text'] ?? ''),
            ));
        }
    }
}
