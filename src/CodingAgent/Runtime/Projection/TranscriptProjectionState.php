<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Projection;

/**
 * Pure state holder for the transcript projection: owns the block
 * accumulator (map + order list), sequence counter, and shared
 * computation helpers.
 *
 * This class lives in AppRuntimeProjection (zero production dependencies
 * outside its own namespace) — no Symfony, Protocol, TUI, or AgentCore
 * imports.
 *
 * The {@see TranscriptProjector} facade in ProjectionPipeline injects
 * this and feeds blocks from Symfony subscriber handlers.
 */
final class TranscriptProjectionState
{
    /** @var array<string, TranscriptBlock> indexed by block ID */
    private array $blocks = [];

    /** @var list<string> ordered block IDs */
    private array $order = [];

    /** Monotonic sequence counter for new blocks. Reset on replay. */
    private int $nextSeq = 0;

    // ── State mutation ──────────────────────────────────────────────────────

    /**
     * Add a block to the accumulator.
     *
     * If a block with the same ID already exists (replay duplicate),
     * it is replaced in-place (order is unaffected).
     */
    public function addBlock(TranscriptBlock $block): void
    {
        if (!\array_key_exists($block->id, $this->blocks)) {
            $this->order[] = $block->id;
        }
        $this->blocks[$block->id] = $block;
    }

    /**
     * Look up an existing block by ID.
     */
    public function getBlock(string $id): ?TranscriptBlock
    {
        return $this->blocks[$id] ?? null;
    }

    /**
     * Replace an existing block in-place (does not affect order).
     */
    public function updateBlock(string $id, TranscriptBlock $block): void
    {
        $this->blocks[$id] = $block;
    }

    /**
     * Remove a block by ID (both from the map and the order list).
     *
     * No-op when the block does not exist.
     */
    public function removeBlock(string $id): void
    {
        if (!\array_key_exists($id, $this->blocks)) {
            return;
        }
        unset($this->blocks[$id]);
        $this->order = array_values(
            array_filter($this->order, static fn (string $oid): bool => $oid !== $id),
        );
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Return the current ordered list of transcript blocks.
     *
     * @return list<TranscriptBlock>
     */
    public function blocks(): array
    {
        $result = [];
        foreach ($this->order as $id) {
            $result[] = $this->blocks[$id];
        }

        return $result;
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->blocks = [];
        $this->order = [];
        $this->nextSeq = 0;
    }

    // ── Sequence counter ─────────────────────────────────────────────────────

    /**
     * Consume and return the next monotonic sequence number.
     */
    public function nextSeq(): int
    {
        return $this->nextSeq++;
    }

    // ── Shared helpers (called from subscribers via the facade) ────────────

    /**
     * Build the common assistant metadata map from event payload.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, mixed>
     */
    public function buildAssistantMeta(array $p): array
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
     * Choose an error block id: prefer the payload block_id, then message_id,
     * then a generated fallback.
     *
     * @param array<string, mixed> $p
     */
    public function pickErrorBlockId(array $p, string $messageId): string
    {
        $blockId = (string) ($p['block_id'] ?? '');

        return '' !== $blockId ? $blockId : ('' !== $messageId ? $messageId : 'error_'.$this->nextSeq());
    }

    /**
     * Add or update a tool-result block (completed, failed, or cancelled).
     *
     * @param array<string, mixed> $meta
     */
    public function upsertToolResultBlock(
        string $blockId,
        string $runId,
        string $text,
        array $meta,
        bool $streaming,
    ): void {
        $existing = $this->getBlock($blockId);
        if (null !== $existing) {
            $this->updateBlock($blockId, $existing->with(
                text: '' !== $text ? $text : $existing->text,
                streaming: $streaming,
                meta: $meta,
            ));
        } else {
            $this->addBlock(new TranscriptBlock(
                id: $blockId,
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: $runId,
                seq: $this->nextSeq(),
                text: $text,
                meta: $meta,
                streaming: $streaming,
            ));
        }
    }

    /**
     * Mark all streaming blocks for the given run as finalized (non-streaming).
     */
    public function cancelActiveStreamingBlocks(string $runId): void
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->streaming && $block->runId === $runId) {
                $this->blocks[$id] = $block->with(streaming: false);
            }
        }
    }

    /**
     * Add a cancellation block for turn/run cancelled events.
     */
    public function addCancelledBlock(string $runId, string $reason, string $scope): void
    {
        $seq = $this->nextSeq();

        $this->addBlock(new TranscriptBlock(
            id: "cancel_{$scope}_{$seq}",
            kind: TranscriptBlockKindEnum::Cancelled,
            runId: $runId,
            seq: $seq,
            text: "{$scope} cancelled".('' !== $reason ? " ({$reason})" : ''),
            meta: [
                'reason' => $reason,
                'scope' => $scope,
            ],
            streaming: false,
        ));
    }

    /**
     * Remove ToolCall blocks whose tool_call_id has no matching ToolResult
     * block, cleaning up orphaned/phantom entries that were never executed.
     *
     * Common in parallel LLM responses where multiple non-empty tool calls
     * are emitted but only one is actually accepted for execution.
     */
    /**
     * Remove still-streaming ToolCall blocks that were never finalized
     * by ToolCallComplete.  Safe to call mid-turn — this only removes
     * blocks the LLM announced but never completed, not blocks that are
     * finalized and awaiting execution.
     *
     * Called from onToolExecutionStarted() so phantom streaming blocks
     * are cleaned at the earliest reliable moment rather than waiting
     * for the next TurnStarted.
     */
    public function removePhantomStreamingToolCallBlocks(): void
    {
        $hasFinalized = false;

        foreach ($this->blocks as $block) {
            if (TranscriptBlockKindEnum::ToolCall === $block->kind && !$block->streaming) {
                $hasFinalized = true;
                break;
            }
        }

        // No finalized tool call yet — nothing to compare against.
        if (!$hasFinalized) {
            return;
        }

        foreach ($this->blocks as $id => $block) {
            if (TranscriptBlockKindEnum::ToolCall !== $block->kind) {
                continue;
            }

            if ($block->streaming) {
                $this->removeBlock($id);
            }
        }
    }

    public function removeOrphanedToolCallBlocks(): void
    {
        $executedIds = [];

        foreach ($this->blocks as $block) {
            if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
                $executedIds[] = $block->meta['tool_call_id'] ?? '';
            }
        }

        // Nothing executed yet — keep all ToolCall blocks.
        if ([] === $executedIds) {
            return;
        }

        foreach ($this->blocks as $id => $block) {
            if (TranscriptBlockKindEnum::ToolCall !== $block->kind) {
                continue;
            }

            $callId = $block->meta['tool_call_id'] ?? '';
            if (!\in_array($callId, $executedIds, true)) {
                $this->removeBlock($id);
            }
        }
    }

    /**
     * Finalize all streaming blocks belonging to a given message.
     */
    /**
     * Check whether any projected block references the given message ID.
     *
     * Used by AssistantStreamProjectionSubscriber to determine whether
     * a non-streaming message_completed event (e.g. placeholder) needs
     * a fresh block created.
     */
    public function hasAnyBlockForMessageId(string $messageId): bool
    {
        foreach ($this->blocks as $block) {
            if (($block->meta['message_id'] ?? '') === $messageId) {
                return true;
            }
        }

        return false;
    }

    public function finalizeMessageBlocks(string $messageId): void
    {
        foreach ($this->blocks as $id => $block) {
            if (($block->meta['message_id'] ?? '') === $messageId && $block->streaming) {
                $this->blocks[$id] = $block->finalize();
            }
        }
    }

    /**
     * Convert tool arguments to a compact text representation.
     *
     * @param array<string, mixed>|list<mixed> $arguments
     */
    public function argumentsToText(array $arguments): string
    {
        if ([] === $arguments) {
            return '()';
        }

        $parts = [];
        foreach ($arguments as $key => $value) {
            if (\is_string($value)) {
                $parts[] = "{$key}: \"{$value}\"";
            } else {
                $parts[] = "{$key}: ".json_encode($value, \JSON_THROW_ON_ERROR);
            }
        }

        return '('.implode(', ', $parts).')';
    }
}
