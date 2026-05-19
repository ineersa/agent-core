<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Projects ordered RuntimeEvents into stable TranscriptBlocks.
 *
 * The projector consumes ordered runtime events (as arrays matching the
 * {@see \Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent} shape) and
 * maintains a deterministic transcript block list. Replaying the same
 * event sequence from a reset state produces the same block output every
 * time.
 *
 * This class accepts plain arrays (not typed RuntimeEvent objects) so it
 * stays within the AppRuntimeProjection deptrac boundary (no dependencies).
 */
final class TranscriptProjector
{
    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    /** @var array<string, TranscriptBlock> Active streaming blocks keyed by block_id from the event payload */
    private array $active = [];

    /** Monotonic sequence counter for block ordering. Reset on replay. */
    private int $nextSeq = 0;

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Accept a runtime event and update the projection.
     *
     * Unknown event types are silently ignored.
     *
     * @param array{type: string, runId: string, seq: int, payload: array<string, mixed>, v?: int} $event
     */
    public function accept(array $event): void
    {
        $type = $event['type'];

        match ($type) {
            'user.message_submitted' => $this->handleUserMessageSubmitted($event),
            'assistant.text_started' => $this->handleTextStarted($event),
            'assistant.text_delta' => $this->handleTextDelta($event),
            'assistant.text_completed' => $this->handleTextCompleted($event),
            'assistant.thinking_started' => $this->handleThinkingStarted($event),
            'assistant.thinking_delta' => $this->handleThinkingDelta($event),
            'assistant.thinking_completed' => $this->handleThinkingCompleted($event),
            'assistant.message_completed' => $this->handleMessageCompleted($event),
            'assistant.message_failed' => $this->handleMessageFailed($event),
            'assistant.message_started' => null, // Marker only; blocks are created by text_started/thinking_started
            default => null,
        };
    }

    /**
     * Return the current ordered list of transcript blocks.
     *
     * Active streaming blocks are included. Blocks accumulate in creation
     * order and are never removed.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array
    {
        return $this->blocks;
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->blocks = [];
        $this->active = [];
        $this->nextSeq = 0;
    }

    // ── User message ────────────────────────────────────────────────────────

    private function handleUserMessageSubmitted(array $event): void
    {
        $p = $event['payload'];

        $this->blocks[] = new TranscriptBlock(
            id: (string) ($p['message_id'] ?? ''),
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: $event['runId'],
            seq: $this->nextSeq++,
            text: (string) ($p['text'] ?? ''),
        );
    }

    // ── Assistant text block ─────────────────────────────────────────────────

    private function handleTextStarted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: $event['runId'],
            seq: $this->nextSeq++,
            text: (string) ($p['text'] ?? ''),
            meta: $this->buildMeta($p),
            streaming: true,
        );

        $this->blocks[] = $block;
        $this->active[$blockId] = $block;
    }

    private function handleTextDelta(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');

        $block = $this->active[$blockId] ?? null;
        if (null === $block) {
            return; // Orphan delta — no matching active block
        }

        $updated = $block->appendText($delta);
        $this->active[$blockId] = $updated;
        $this->replaceInBlocks($blockId, $updated);
    }

    private function handleTextCompleted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $this->active[$blockId] ?? null;
        if (null === $block) {
            return;
        }

        $updated = $block
            ->with(text: isset($p['text']) ? (string) $p['text'] : $block->text)
            ->finalize();
        $this->active[$blockId] = $updated;
        $this->replaceInBlocks($blockId, $updated);
    }

    // ── Assistant thinking block ─────────────────────────────────────────────

    private function handleThinkingStarted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = new TranscriptBlock(
            id: $blockId,
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: $event['runId'],
            seq: $this->nextSeq++,
            text: (string) ($p['text'] ?? ''),
            meta: $this->buildMeta($p),
            streaming: true,
            collapsed: true,
        );

        $this->blocks[] = $block;
        $this->active[$blockId] = $block;
    }

    private function handleThinkingDelta(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');
        $delta = (string) ($p['delta'] ?? '');

        $block = $this->active[$blockId] ?? null;
        if (null === $block) {
            return;
        }

        $updated = $block->appendText($delta);
        $this->active[$blockId] = $updated;
        $this->replaceInBlocks($blockId, $updated);
    }

    private function handleThinkingCompleted(array $event): void
    {
        $p = $event['payload'];
        $blockId = (string) ($p['block_id'] ?? '');

        $block = $this->active[$blockId] ?? null;
        if (null === $block) {
            return;
        }

        $updated = $block
            ->with(text: isset($p['text']) ? (string) $p['text'] : $block->text)
            ->finalize();
        $this->active[$blockId] = $updated;
        $this->replaceInBlocks($blockId, $updated);
    }

    // ── Message lifecycle ────────────────────────────────────────────────────

    private function handleMessageCompleted(array $event): void
    {
        $p = $event['payload'];
        $messageId = (string) ($p['message_id'] ?? '');

        foreach ($this->active as $blockId => $block) {
            if (($block->meta['message_id'] ?? '') === $messageId && $block->streaming) {
                $updated = $block->finalize();
                $this->active[$blockId] = $updated;
                $this->replaceInBlocks($blockId, $updated);
            }
        }
    }

    private function handleMessageFailed(array $event): void
    {
        $p = $event['payload'];
        $messageId = (string) ($p['message_id'] ?? '');

        // Finalize any streaming blocks belonging to this message
        foreach ($this->active as $blockId => $block) {
            if (($block->meta['message_id'] ?? '') === $messageId && $block->streaming) {
                $updated = $block->finalize();
                $this->active[$blockId] = $updated;
                $this->replaceInBlocks($blockId, $updated);
            }
        }

        // Append an error block
        $this->blocks[] = new TranscriptBlock(
            id: $this->pickErrorBlockId($p, $messageId),
            kind: TranscriptBlockKindEnum::Error,
            runId: $event['runId'],
            seq: $this->nextSeq++,
            text: (string) ($p['text'] ?? 'Assistant message failed'),
            meta: [
                'message_id' => $messageId,
                'stop_reason' => (string) ($p['stop_reason'] ?? 'error'),
            ],
        );
    }

    // ── Internal helpers ─────────────────────────────────────────────────────

    /**
     * Build the common assistant metadata map from event payload.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    private function buildMeta(array $p): array
    {
        $meta = [
            'message_id' => (string) ($p['message_id'] ?? ''),
            'content_index' => (int) ($p['content_index'] ?? 0),
        ];

        if (isset($p['model'])) {
            $meta['model'] = (string) $p['model'];
        }

        if (isset($p['stop_reason'])) {
            $meta['stop_reason'] = (string) $p['stop_reason'];
        }

        return $meta;
    }

    /**
     * Find and replace a block in the block list by id.
     */
    private function replaceInBlocks(string $blockId, TranscriptBlock $updated): void
    {
        foreach ($this->blocks as $i => $block) {
            if ($block->id === $blockId) {
                $this->blocks[$i] = $updated;

                return;
            }
        }
    }

    /**
     * Choose an error block id: prefer the payload block_id, then message_id, then a generated fallback.
     *
     * @param array<string, mixed> $p
     */
    private function pickErrorBlockId(array $p, string $messageId): string
    {
        $blockId = (string) ($p['block_id'] ?? '');

        return '' !== $blockId ? $blockId : ('' !== $messageId ? $messageId : 'error_'.$this->nextSeq);
    }
}
