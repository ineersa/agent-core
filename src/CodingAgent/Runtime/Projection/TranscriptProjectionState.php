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

    /**
     * Block ID → index into {@see $order} for O(1) removal/reindex.
     *
     * @var array<string, int>
     */
    private array $orderIndex = [];

    /** Monotonic sequence counter for new blocks. Reset on replay. */
    private int $nextSeq = 0;

    /**
     * Dirty block IDs since the last {@see drainChanges()} call.
     *
     * @var array<string, true>
     */
    private array $dirtyIds = [];

    /**
     * Dirty IDs in first-mark order (O(changes) drain; sorted by canonical
     * order index when emitted so multi-block batches stay ordered).
     *
     * @var list<string>
     */
    private array $dirtyOrder = [];

    /**
     * Removed block IDs since the last {@see drainChanges()} call.
     *
     * @var array<string, true>
     */
    private array $removedIds = [];

    /**
     * Highest compaction.completed runtime event seq whose rolling retention
     * window has already been applied on this projection state.
     *
     * Prevents duplicate completion delivery from advancing the window twice.
     * Reset with {@see reset()}. Canonical seq 0 never participates in dedup.
     */
    private int $lastAppliedCompactionEventSeq = 0;

    /**
     * Previous compaction.completed block id that became the retention floor
     * during the outstanding dirty window (cleared by {@see drainChanges()}).
     *
     * Session application uses this to drop local UI blocks that belonged to
     * the evicted segment while keeping newer locals.
     */
    private ?string $pendingRetentionFloorBlockId = null;

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
            $this->orderIndex[$block->id] = \count($this->order);
            $this->order[] = $block->id;
        }
        $this->blocks[$block->id] = $block;
        $this->markDirty($block->id);
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
        $this->markDirty($id);
    }

    /**
     * Remove a block by ID (both from the map and the order list).
     *
     * No-op when the block does not exist. Order splice is O(tail), not a
     * full {@see array_filter()} rebuild of the order list.
     */
    public function removeBlock(string $id): void
    {
        if (!\array_key_exists($id, $this->blocks)) {
            return;
        }
        unset($this->blocks[$id]);
        $idx = $this->orderIndex[$id] ?? null;
        if (null !== $idx) {
            array_splice($this->order, $idx, 1);
            unset($this->orderIndex[$id]);
            $count = \count($this->order);
            for ($i = $idx; $i < $count; ++$i) {
                $this->orderIndex[$this->order[$i]] = $i;
            }
        }
        if (isset($this->dirtyIds[$id])) {
            unset($this->dirtyIds[$id]);
            // Leave stale id in dirtyOrder; drain skips missing dirty ids.
        }
        $this->removedIds[$id] = true;
    }

    /**
     * Advance the rolling compaction retention window for a successful
     * compaction.completed event.
     *
     * Compaction #1 keeps conversation #1 (no prior completed marker).
     * Compaction #2 evicts conversation #1 by pruning strictly before the
     * previous completed marker. Compaction #3 evicts conversation #2.
     *
     * Duplicate delivery of the same positive event seq is a pure no-op.
     * Seq 0 is treated as non-dedupable and always applies.
     *
     * ToolCall blocks before the floor remain when a retained ToolResult still
     * references their tool_call_id.
     *
     * @return bool True when retention advanced (caller should append the new marker)
     */
    public function advanceCompactionRetention(int $eventSeq, ?string $previousCompletedBlockId): bool
    {
        if ($eventSeq > 0 && $eventSeq <= $this->lastAppliedCompactionEventSeq) {
            return false;
        }

        if (null !== $previousCompletedBlockId) {
            $this->pruneBlocksBefore($previousCompletedBlockId);
            $this->pendingRetentionFloorBlockId = $previousCompletedBlockId;
        }

        if ($eventSeq > $this->lastAppliedCompactionEventSeq) {
            $this->lastAppliedCompactionEventSeq = $eventSeq;
        }

        return true;
    }

    /**
     * Evict blocks strictly before {@see $floorBlockId}, keeping the floor and
     * everything after it.
     *
     * ToolCall blocks before the floor remain when a retained ToolResult still
     * references their tool_call_id, so open exchanges are not orphaned.
     */
    private function pruneBlocksBefore(string $floorBlockId): void
    {
        $floorIdx = $this->orderIndex[$floorBlockId] ?? null;
        if (null === $floorIdx || $floorIdx <= 0) {
            return;
        }

        /** @var array<string, true> $requiredCallIds */
        $requiredCallIds = [];
        $orderCount = \count($this->order);
        for ($i = $floorIdx; $i < $orderCount; ++$i) {
            $block = $this->blocks[$this->order[$i]] ?? null;
            if (null === $block || TranscriptBlockKindEnum::ToolResult !== $block->kind) {
                continue;
            }
            $callId = $block->meta['tool_call_id'] ?? '';
            if (\is_string($callId) && '' !== $callId) {
                $requiredCallIds[$callId] = true;
            }
        }

        $keptBefore = [];
        for ($i = 0; $i < $floorIdx; ++$i) {
            $id = $this->order[$i];
            $block = $this->blocks[$id] ?? null;
            if (null === $block) {
                continue;
            }
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $callId = $block->meta['tool_call_id'] ?? '';
                if (\is_string($callId) && isset($requiredCallIds[$callId])) {
                    $keptBefore[] = $id;
                    continue;
                }
            }
            unset($this->blocks[$id]);
            if (isset($this->dirtyIds[$id])) {
                unset($this->dirtyIds[$id]);
            }
            $this->removedIds[$id] = true;
        }

        $retainedTail = array_slice($this->order, $floorIdx);
        $this->order = [...$keptBefore, ...$retainedTail];
        $this->orderIndex = [];
        foreach ($this->order as $idx => $id) {
            $this->orderIndex[$id] = $idx;
        }
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    /**
     * Return the current ordered list of transcript blocks.
     *
     * Used for bootstrap/resume/history-position replacement. Ordinary live polls should
     * prefer {@see drainChanges()} so finalized history is not re-materialized.
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
     * Drain dirty/removed block IDs into an incremental change set and clear tracking.
     *
     * Live TUI state always *merges* projector deltas (resume/history-position snapshots live
     * outside the projector). Full replacement is assembled by callers via
     * {@see blocks()} / {@see TranscriptChangeSet::full()}, never by this drain.
     *
     * Complexity: O(number of dirty/removed IDs), not O(history).
     */
    public function drainChanges(): TranscriptChangeSet
    {
        // Deduplicate first-mark order: add → remove → re-add can append the same
        // id twice after remove clears dirtyIds but leaves a stale dirtyOrder entry.
        $uniqueDirty = [];
        foreach ($this->dirtyOrder as $id) {
            if (!isset($this->dirtyIds[$id]) || !isset($this->blocks[$id])) {
                continue;
            }
            $uniqueDirty[$id] = true;
        }
        $dirty = array_keys($uniqueDirty);

        // Canonical order among dirty blocks (appends stay after earlier dirty ids).
        usort(
            $dirty,
            fn (string $a, string $b): int => ($this->orderIndex[$a] ?? 0) <=> ($this->orderIndex[$b] ?? 0),
        );

        $upserts = [];
        foreach ($dirty as $id) {
            $upserts[] = $this->blocks[$id];
        }

        $removals = array_keys($this->removedIds);
        $this->dirtyIds = [];
        $this->dirtyOrder = [];
        $this->removedIds = [];

        $retentionFloorBlockId = $this->pendingRetentionFloorBlockId;
        $this->pendingRetentionFloorBlockId = null;

        return TranscriptChangeSet::incremental($upserts, $removals, $retentionFloorBlockId);
    }

    /**
     * Reset all internal state so a fresh replay produces the same output.
     */
    public function reset(): void
    {
        $this->blocks = [];
        $this->order = [];
        $this->orderIndex = [];
        $this->nextSeq = 0;
        $this->dirtyIds = [];
        $this->dirtyOrder = [];
        $this->removedIds = [];
        $this->lastAppliedCompactionEventSeq = 0;
        $this->pendingRetentionFloorBlockId = null;
    }

    /**
     * Replace the projected block list without replaying events.
     *
     * Clears dirty/removal/pending-retention tracking. Does not change
     * {@see $lastAppliedCompactionEventSeq} — callers that need a clean
     * retention dedup cursor must {@see reset()} first.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function replaceBlocks(array $blocks): void
    {
        $this->blocks = [];
        $this->order = [];
        $this->orderIndex = [];
        $this->dirtyIds = [];
        $this->dirtyOrder = [];
        $this->removedIds = [];
        $this->pendingRetentionFloorBlockId = null;
        $this->nextSeq = 0;

        foreach ($blocks as $block) {
            $this->orderIndex[$block->id] = \count($this->order);
            $this->order[] = $block->id;
            $this->blocks[$block->id] = $block;
            if ($block->seq >= $this->nextSeq) {
                $this->nextSeq = $block->seq + 1;
            }
        }
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
     * Remove all still-streaming blocks for the given run.
     *
     * Streaming blocks represent transient in-progress UI state
     * (thinking deltas, partial tool-call placeholders, Running\u2026
     * tool results) that must not become permanent history.  When a turn
     * is cancelled, a run fails, or a run is cancelled, these blocks are
     * discarded so they don't appear in the transcript on resume/replay.
     *
     * Completed (non-streaming) blocks from the same run are preserved.
     * Cancellation blocks added after this call are themselves
     * non-streaming so they survive.
     */
    public function removeActiveStreamingBlocks(string $runId): void
    {
        foreach ($this->blocks as $id => $block) {
            if ($block->streaming && $block->runId === $runId) {
                $this->removeBlock($id);
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

    /**
     * Remove ToolCall blocks whose tool_call_id has no matching ToolResult
     * block, cleaning up orphaned/phantom entries that were never executed.
     *
     * Common in parallel LLM responses where multiple non-empty tool calls
     * are emitted but only one is actually accepted for execution.
     */
    public function removeOrphanedToolCallBlocks(): void
    {
        /** @var array<string, true> $executedIds */
        $executedIds = [];

        foreach ($this->blocks as $block) {
            if (TranscriptBlockKindEnum::ToolResult !== $block->kind) {
                continue;
            }
            $callId = $block->meta['tool_call_id'] ?? '';
            if (\is_string($callId) && '' !== $callId) {
                $executedIds[$callId] = true;
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
            if (!\is_string($callId) || '' === $callId || !isset($executedIds[$callId])) {
                $this->removeBlock($id);
            }
        }
    }

    /**
     * Check whether a block of a specific kind with the given message ID
     * exists.  Used to avoid creating duplicate canonical blocks on replay
     * when the live streaming path already produced the same block.
     */
    public function hasBlockOfKindForMessageId(string $messageId, TranscriptBlockKindEnum $kind): bool
    {
        foreach ($this->blocks as $block) {
            if ($block->kind === $kind
                && (($block->meta['message_id'] ?? '') === $messageId)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finalize all streaming blocks belonging to a given message.
     */
    public function finalizeMessageBlocks(string $messageId): void
    {
        foreach ($this->blocks as $id => $block) {
            if (($block->meta['message_id'] ?? '') === $messageId && $block->streaming) {
                $this->updateBlock($id, $block->finalize());
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

    private function markDirty(string $id): void
    {
        unset($this->removedIds[$id]);
        if (isset($this->dirtyIds[$id])) {
            return;
        }
        $this->dirtyIds[$id] = true;
        $this->dirtyOrder[] = $id;
    }
}
