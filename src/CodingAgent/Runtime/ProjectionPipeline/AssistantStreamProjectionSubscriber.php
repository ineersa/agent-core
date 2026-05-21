<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects assistant streaming events (text and thinking blocks)
 * and message-completion/failure events.
 */
final readonly class AssistantStreamProjectionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::AssistantMessageStarted->value => 'onMessageStarted',
            RuntimeEventTypeEnum::AssistantTextStarted->value => 'onTextStarted',
            RuntimeEventTypeEnum::AssistantTextDelta->value => 'onTextDelta',
            RuntimeEventTypeEnum::AssistantTextCompleted->value => 'onTextCompleted',
            RuntimeEventTypeEnum::AssistantThinkingStarted->value => 'onThinkingStarted',
            RuntimeEventTypeEnum::AssistantThinkingDelta->value => 'onThinkingDelta',
            RuntimeEventTypeEnum::AssistantThinkingCompleted->value => 'onThinkingCompleted',
            RuntimeEventTypeEnum::AssistantMessageCompleted->value => 'onMessageCompleted',
            RuntimeEventTypeEnum::AssistantMessageFailed->value => 'onMessageFailed',
        ];
    }

    /**
     * Marker event — no block created.
     */
    public function onMessageStarted(TranscriptProjectionEvent $event): void
    {
        // Intentionally blank: marker event only
    }

    // ── Text block ───────────────────────────────────────────────────────────

    public function onTextStarted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: $state->buildAssistantMeta($p),
            streaming: true,
        ));
    }

    public function onTextDelta(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');
        // The stream observer stores text under the 'text' key,
        // not 'delta' (which is only used in mock-style tests).
        $delta = (string) ($p['text'] ?? '');

        $block = $state->getBlock($blockId);
        if (null === $block || false === $block->streaming) {
            return;
        }

        $state->updateBlock($blockId, $block->appendText($delta));
    }

    public function onTextCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $state->getBlock($blockId);
        if (null === $block) {
            return;
        }

        $state->updateBlock($blockId, $block
            ->with(text: isset($p['text']) ? (string) $p['text'] : $block->text)
            ->finalize(),
        );
    }

    // ── Thinking block ───────────────────────────────────────────────────────

    public function onThinkingStarted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: $state->buildAssistantMeta($p),
            streaming: true,
            collapsed: true,
        ));
    }

    public function onThinkingDelta(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');
        // The stream observer stores thinking text under the 'thinking'
        // key (not 'delta' like text deltas).
        $delta = (string) ($p['thinking'] ?? '');

        $block = $state->getBlock($blockId);
        if (null === $block || false === $block->streaming) {
            return;
        }

        $state->updateBlock($blockId, $block->appendText($delta));
    }

    public function onThinkingCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $state->getBlock($blockId);
        if (null === $block) {
            return;
        }

        // Thinking text arrives under the 'thinking' key from the stream
        // observer, not 'text'.
        $thinkingText = (string) ($p['thinking'] ?? '');
        $state->updateBlock($blockId, $block
            ->with(text: '' !== $thinkingText ? $thinkingText : $block->text)
            ->finalize(),
        );
    }

    // ── Message lifecycle ────────────────────────────────────────────────────

    public function onMessageCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $messageId = (string) ($p['message_id'] ?? '');

        $event->state->finalizeMessageBlocks($messageId);

        $text = (string) ($p['text'] ?? '');
        if ('' === $text) {
            return;
        }

        if (!$event->state->hasAnyBlockForMessageId($messageId)) {
            $blockId = 'msg_'.$messageId;
            $event->state->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::AssistantMessage,
                runId: $event->runId(),
                seq: $event->state->nextSeq(),
                text: $text,
                meta: $event->state->buildAssistantMeta($p),
                streaming: false,
            ));
        }
    }

    public function onMessageFailed(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $messageId = (string) ($p['message_id'] ?? '');

        $state->finalizeMessageBlocks($messageId);

        $state->addBlock(new TranscriptBlock(
            id: $state->pickErrorBlockId($p, $messageId),
            kind: TranscriptBlockKindEnum::Error,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? 'Assistant message failed'),
            meta: [
                'message_id' => $messageId,
                'stop_reason' => (string) ($p['stop_reason'] ?? 'error'),
            ],
        ));
    }
}
