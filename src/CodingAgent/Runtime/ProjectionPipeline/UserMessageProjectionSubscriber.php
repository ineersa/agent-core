<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects user-message events into UserMessage transcript blocks.
 *
 * Queued steer/follow-up feedback is not projected here; the TUI pending-queue
 * widget above the editor renders user.message_queued until apply.
 *
 * Entire-message `<system-reminder>...</system-reminder>` wrappers (e.g. context-
 * budget wrap-up appends) project as System/warning so the transcript shows ⚠
 * guidance instead of ordinary ❯ Markdown user text. Canonical event text is
 * unchanged; only presentation classification happens here.
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
        $text = (string) ($p['text'] ?? '');

        $state->addBlock($this->projectUserTextBlock(
            id: (string) ($p['message_id'] ?? ''),
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $text,
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
            $state->addBlock($this->projectUserTextBlock(
                id: (string) ($userMsg['message_id'] ?? ''),
                runId: $event->runId(),
                seq: $state->nextSeq(),
                text: (string) ($userMsg['text'] ?? ''),
            ));
        }
    }

    private function projectUserTextBlock(string $id, string $runId, int $seq, string $text): TranscriptBlock
    {
        $reminderBody = self::extractSystemReminderBody($text);
        if (null !== $reminderBody) {
            return new TranscriptBlock(
                id: $id,
                kind: TranscriptBlockKindEnum::System,
                runId: $runId,
                seq: $seq,
                text: $reminderBody,
                meta: ['severity' => 'warning'],
            );
        }

        return new TranscriptBlock(
            id: $id,
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $runId,
            seq: $seq,
            text: $text,
        );
    }

    /**
     * Return inner prose when the entire trimmed text is a complete
     * <system-reminder> wrapper; otherwise null (partial/embedded tags stay user text).
     */
    private static function extractSystemReminderBody(string $text): ?string
    {
        $trimmed = trim($text);
        if (1 !== preg_match('/\A<system-reminder>\s*(.*?)\s*<\/system-reminder>\z/s', $trimmed, $matches)) {
            return null;
        }

        $inner = trim($matches[1]);

        return '' !== $inner ? $inner : null;
    }
}
