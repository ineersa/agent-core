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
    /**
     * Block id shared by a queued user message and its reconciled canonical block, so the
     * canonical block replaces the pending one in place (same id) instead of appending.
     */
    private const QUEUED_BLOCK_ID_FORMAT = 'user_queued_%s_%s';

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::UserMessageSubmitted->value => 'onUserMessageSubmitted',
            RuntimeEventTypeEnum::UserMessageQueued->value => 'onUserMessageQueued',
            RuntimeEventTypeEnum::RunStarted->value => 'onRunStarted',
        ];
    }

    public function onUserMessageQueued(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $idempotencyKey = (string) ($p['idempotency_key'] ?? '');
        // idempotency_key is always present for steer/follow_up (SHA-256 hash from AgentRunner);
        // the message_id branch is a defensive fallback for malformed/legacy payloads.
        $blockId = '' !== $idempotencyKey
            ? $this->queuedBlockId($event->runId(), $idempotencyKey)
            : (string) ($p['message_id'] ?? '');

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::UserMessageQueued,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: ['idempotency_key' => $idempotencyKey],
        ));
    }

    public function onUserMessageSubmitted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;

        $idempotencyKey = (string) ($p['idempotency_key'] ?? '');
        $blockId = (string) ($p['message_id'] ?? '');
        if ('' !== $idempotencyKey) {
            // Reconcile the pending user.message_queued block into the canonical user message by
            // reusing its block id. TranscriptProjectionState::addBlock() replaces a block in place
            // (preserving its order position) when the id already exists, so the pending ⏳ row
            // becomes the finalized ❯ row at the same position — both live (RuntimeEventPoller syncs
            // by block id and never prunes removed ids) and on resume/replay (rebuilt from events).
            $blockId = $this->queuedBlockId($event->runId(), $idempotencyKey);
        }

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
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

    private function queuedBlockId(string $runId, string $idempotencyKey): string
    {
        return \sprintf(self::QUEUED_BLOCK_ID_FORMAT, $runId, $idempotencyKey);
    }
}
