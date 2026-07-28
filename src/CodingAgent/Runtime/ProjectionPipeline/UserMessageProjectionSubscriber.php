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
 * Context-budget wrap-up appends carry opaque AgentMessage metadata
 * `system_reminder=true` plus model-visible `<system-reminder>` wrapper text.
 * Only that provenance + an exact complete non-empty wrapper project as
 * System/warning. Identical manually typed user text without the marker stays
 * UserMessage. Initial run.started prompts are never content-sniffed.
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
        $metadata = \is_array($p['metadata'] ?? null) ? $p['metadata'] : [];

        $state->addBlock($this->projectSubmittedUserTextBlock(
            id: (string) ($p['message_id'] ?? ''),
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: $text,
            metadata: $metadata,
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
            // Initial prompts are ordinary user text; never reclassify by content.
            $state->addBlock(new TranscriptBlock(
                id: (string) ($userMsg['message_id'] ?? ''),
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: $event->runId(),
                seq: $state->nextSeq(),
                text: (string) ($userMsg['text'] ?? ''),
            ));
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function projectSubmittedUserTextBlock(
        string $id,
        string $runId,
        int $seq,
        string $text,
        array $metadata,
    ): TranscriptBlock {
        if (true === ($metadata['system_reminder'] ?? null)) {
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
