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

        // Use step_id as message_id so canonical message_completed can
        // find and finalize this streaming block instead of creating a duplicate.
        if (!isset($p['message_id']) && isset($p['step_id'])) {
            $p['message_id'] = (string) $p['step_id'];
        }

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

        // Use step_id as message_id so canonical message_completed can
        // find and finalize this streaming block instead of creating a duplicate.
        if (!isset($p['message_id']) && isset($p['step_id'])) {
            $p['message_id'] = (string) $p['step_id'];
        }

        $state->addBlock(new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: $event->runId(),
            seq: $state->nextSeq(),
            text: (string) ($p['text'] ?? ''),
            meta: $state->buildAssistantMeta($p),
            streaming: true,
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

        // ── Reconstruct canonical thinking block ──
        //
        // Streaming thinking deltas are not persisted in events.jsonl.
        // On replay the canonical llm_step_completed carries
        // details.thinking with the full thinking text.  Create a
        // non-streaming, not collapsed AssistantThinking block so resume
        // shows the same thinking content the user saw in the live TUI.
        // (Display collapse is a local rendering policy, not a projection
        // concern — see TranscriptDisplayConfig::thinkingVisible.)
        //
        // Thinking is created FIRST so it appears before assistant text
        // and tool-call blocks, matching live projection order.
        $canonicalThinking = (string) ($p['details']['thinking'] ?? '');
        if ('' !== $canonicalThinking
            && !$event->state->hasBlockOfKindForMessageId($messageId, TranscriptBlockKindEnum::AssistantThinking)
        ) {
            $thinkingBlockId = 'think_'.$messageId;
            $event->state->addBlock(new TranscriptBlock(
                id: $thinkingBlockId,
                kind: TranscriptBlockKindEnum::AssistantThinking,
                runId: $event->runId(),
                seq: $event->state->nextSeq(),
                text: $canonicalThinking,
                meta: $event->state->buildAssistantMeta($p),
                streaming: false,
            ));
        }

        // ── Reconstruct canonical (non-streaming) text block ──
        //
        // When the live streaming path already produced a text block,
        // hasBlockOfKindForMessageId returns true and we skip.  On
        // replay (no streaming deltas in events.jsonl) there are no
        // blocks yet, so we create the non-streaming text block from
        // the canonical llm_step_completed text.
        //
        // We check specifically for AssistantMessage kind because
        // thinking and tool-call blocks (reconstructed above) also
        // carry the message_id and would cause a false positive.
        $text = (string) ($p['text'] ?? '');
        if ('' !== $text
            && !$event->state->hasBlockOfKindForMessageId($messageId, TranscriptBlockKindEnum::AssistantMessage)
        ) {
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

        // ── Reconstruct canonical tool-call blocks ──
        //
        // Streaming tool-call deltas are not persisted in events.jsonl.
        // On replay the canonical llm_step_completed carries
        // assistant_message.tool_calls with id, name, and arguments.
        // Create non-streaming ToolCall blocks for each call that
        // doesn't already have a block (the live streaming path already
        // created finalized ToolCall blocks during the original session).
        $canonicalToolCalls = $p['tool_calls'] ?? [];
        if (\is_array($canonicalToolCalls) && [] !== $canonicalToolCalls) {
            foreach ($canonicalToolCalls as $tc) {
                if (!\is_array($tc)) {
                    continue;
                }
                $callId = (string) ($tc['id'] ?? '');
                if ('' === $callId) {
                    continue;
                }
                $toolCallBlockId = 'tool_call_'.$callId;
                if (null !== $event->state->getBlock($toolCallBlockId)) {
                    continue; // already exists from streaming path
                }
                $toolName = (string) ($tc['name'] ?? '');
                $arguments = $tc['arguments'] ?? [];
                $argsText = \is_array($arguments) && [] !== $arguments
                    ? $event->state->argumentsToText($arguments)
                    : '()';
                $text = '' !== $toolName ? $toolName.$argsText : $argsText;

                $event->state->addBlock(new TranscriptBlock(
                    id: $toolCallBlockId,
                    kind: TranscriptBlockKindEnum::ToolCall,
                    runId: $event->runId(),
                    seq: $event->state->nextSeq(),
                    text: $text,
                    meta: [
                        'tool_call_id' => $callId,
                        'tool_name' => $toolName,
                        'arguments' => $arguments,
                        'message_id' => $messageId,
                    ],
                    streaming: false,
                ));
            }
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
