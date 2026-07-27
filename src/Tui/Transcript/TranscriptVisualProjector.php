<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;

/**
 * Stateful presentation model: canonical blocks → typed visual nodes + patches.
 *
 * Owns retained block map/order, tool call/result indexes, and current visual
 * nodes/order. Ordinary tail deltas produce dependency-bounded
 * {@see TranscriptVisualPatch} values; bootstrap/resume/leaf/preview/non-tail
 * use explicit full reprojection.
 *
 * Dirty detection is object identity + presentation revision. No text hashing.
 */
final class TranscriptVisualProjector
{
    private const string WELCOME_KEY = '__welcome__';

    private readonly TranscriptBlockWidgetFactory $factory;

    /** @var array<string, TranscriptBlock> */
    private array $blocksById = [];

    /** @var list<string> */
    private array $blockOrder = [];

    /** @var array<string, int> */
    private array $blockIndex = [];

    /**
     * tool_call_id → ToolCall block id.
     *
     * @var array<string, string>
     */
    private array $toolCallIdByCallId = [];

    /**
     * tool_call_id → list of ToolResult block ids (insertion order).
     *
     * @var array<string, list<string>>
     */
    private array $toolResultIdsByCallId = [];

    /** @var array<string, TranscriptVisualNode> */
    private array $nodesByKey = [];

    /** @var list<string> */
    private array $nodeOrder = [];

    /**
     * Visual key currently owned by a primary block id (non-separator).
     *
     * @var array<string, string>
     */
    private array $visualKeyByPrimaryId = [];

    public function __construct(
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
        ?TranscriptBlockWidgetFactory $factory = null,
    ) {
        $this->factory = $factory ?? new TranscriptBlockWidgetFactory(
            subagentRenderer: new SubagentResultRenderer(
                displayConfig: $displayConfig,
                displayState: $displayState,
            ),
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
    }

    public function factory(): TranscriptBlockWidgetFactory
    {
        return $this->factory;
    }

    public function presentationRevision(): int
    {
        // Theme/config are immutable for the screen lifetime; only preview expansion is live.
        return $this->factory->displayState()->previewableBlocksExpanded ? 1 : 0;
    }

    /**
     * Full replacement: bootstrap, resume, leaf/branch, preview, non-tail/reorder.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function replaceAll(array $blocks): TranscriptVisualPatch
    {
        $this->resetCanonical($blocks);

        return $this->fullReproject();
    }

    /**
     * Ordinary projector delta. Returns a bounded incremental patch when safe;
     * otherwise an explicit full visual snapshot (defined exceptional path).
     */
    public function applyChangeSet(TranscriptChangeSet $changes): TranscriptVisualPatch
    {
        if ($changes->isFull()) {
            return $this->replaceAll($changes->blocks());
        }

        if ($changes->isEmpty()) {
            return TranscriptVisualPatch::content([]);
        }

        // Removals that are not pure tail (or leave tool-index ambiguity) full-reproject.
        foreach ($changes->removals as $id) {
            if (!$this->isKnownBlock($id)) {
                continue;
            }
            if (!$this->isTailBlockId($id) && !$this->isSafeMidRemoval($id)) {
                $this->applyRemovalsAndUpsertsToCanonical($changes);

                return $this->fullReproject();
            }
        }

        // Non-tail upserts (insert into middle) cannot be applied safely incrementally.
        foreach ($changes->upserts as $block) {
            $idx = $this->blockIndex[$block->id] ?? null;
            if (null === $idx) {
                // New block must be a pure append for incremental path.
                // (Projector only ever appends; non-tail insert is exceptional.)
                continue;
            }
            // Existing id mid-list update is OK (streaming); identity-preserving.
        }

        // Detect non-tail append: new id while not extending the end of order is impossible
        // from projector, but guard if session state reorders.
        foreach ($changes->upserts as $block) {
            if (isset($this->blockIndex[$block->id])) {
                continue;
            }
            // New block: only safe as tail append.
            // We accept all new blocks as tail appends (projector semantics).
        }

        $touchedPrimaryIds = [];
        foreach ($changes->removals as $id) {
            if ($this->isKnownBlock($id)) {
                $touchedPrimaryIds[$id] = true;
            }
        }
        foreach ($changes->upserts as $block) {
            $touchedPrimaryIds[$block->id] = true;
        }

        // Neighbor dependencies for separators / empty-assistant-before-question.
        $expanded = $this->expandDependencyIds(array_keys($touchedPrimaryIds));

        // Tool pairing: result arrival also dirties the call's exchange key.
        foreach ($changes->upserts as $block) {
            if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
                $callId = $this->toolCallIdMeta($block);
                if (null !== $callId && isset($this->toolCallIdByCallId[$callId])) {
                    $expanded[$this->toolCallIdByCallId[$callId]] = true;
                }
            }
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $callId = $this->toolCallIdMeta($block);
                if (null !== $callId) {
                    foreach ($this->toolResultIdsByCallId[$callId] ?? [] as $resultId) {
                        $expanded[$resultId] = true;
                    }
                }
            }
        }

        // Apply canonical mutations after dependency expansion uses pre-state neighbors.
        $this->applyRemovalsAndUpsertsToCanonical($changes);

        // After mutation, re-expand for new neighbors (appended question after empty assistant).
        $expanded = $this->expandDependencyIds(array_keys($touchedPrimaryIds));
        foreach ($changes->upserts as $block) {
            $expanded[$block->id] = true;
            if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
                $callId = $this->toolCallIdMeta($block);
                if (null !== $callId && isset($this->toolCallIdByCallId[$callId])) {
                    $expanded[$this->toolCallIdByCallId[$callId]] = true;
                }
            }
        }

        // Structural scan decision uses originally dirty IDs only — neighbor expansion
        // must not promote a pure content update (Error/stream) into full reproject.
        return $this->reprojectAffected(
            array_keys($expanded),
            array_keys($touchedPrimaryIds),
        );
    }

    /**
     * @return list<TranscriptVisualNode>
     */
    public function currentNodes(): array
    {
        $nodes = [];
        foreach ($this->nodeOrder as $key) {
            $nodes[] = $this->nodesByKey[$key];
        }

        return $nodes;
    }

    /**
     * Last visual patch order (for tests / reconciler).
     *
     * @return list<string>
     */
    public function currentOrder(): array
    {
        return $this->nodeOrder;
    }

    /**
     * Ordered canonical blocks retained by this presentation model.
     *
     * @return list<TranscriptBlock>
     */
    public function exportBlocks(): array
    {
        $blocks = [];
        foreach ($this->blockOrder as $id) {
            $blocks[] = $this->blocksById[$id];
        }

        return $blocks;
    }

    /**
     * @param list<TranscriptBlock> $blocks
     */
    private function resetCanonical(array $blocks): void
    {
        $this->blocksById = [];
        $this->blockOrder = [];
        $this->blockIndex = [];
        $this->toolCallIdByCallId = [];
        $this->toolResultIdsByCallId = [];
        $this->visualKeyByPrimaryId = [];

        foreach ($blocks as $block) {
            $this->blockIndex[$block->id] = \count($this->blockOrder);
            $this->blockOrder[] = $block->id;
            $this->blocksById[$block->id] = $block;
            $this->indexToolSide($block);
        }
    }

    private function applyRemovalsAndUpsertsToCanonical(TranscriptChangeSet $changes): void
    {
        if ([] !== $changes->removals) {
            $indices = [];
            foreach ($changes->removals as $id) {
                $idx = $this->blockIndex[$id] ?? null;
                if (null !== $idx) {
                    $indices[] = $idx;
                    $this->unindexToolSide($this->blocksById[$id]);
                    unset($this->blocksById[$id], $this->blockIndex[$id], $this->visualKeyByPrimaryId[$id]);
                }
            }
            if ([] !== $indices) {
                rsort($indices, \SORT_NUMERIC);
                foreach ($indices as $idx) {
                    array_splice($this->blockOrder, $idx, 1);
                }
                $this->rebuildBlockIndex();
            }
        }

        foreach ($changes->upserts as $block) {
            $idx = $this->blockIndex[$block->id] ?? null;
            if (null === $idx) {
                $this->blockIndex[$block->id] = \count($this->blockOrder);
                $this->blockOrder[] = $block->id;
                $this->blocksById[$block->id] = $block;
                $this->indexToolSide($block);
                continue;
            }

            $previous = $this->blocksById[$block->id];
            if ($previous !== $block) {
                $this->unindexToolSide($previous);
                $this->blocksById[$block->id] = $block;
                $this->indexToolSide($block);
            }
        }
    }

    private function rebuildBlockIndex(): void
    {
        $this->blockIndex = [];
        foreach ($this->blockOrder as $i => $id) {
            $this->blockIndex[$id] = $i;
        }
    }

    private function indexToolSide(TranscriptBlock $block): void
    {
        $callId = $this->toolCallIdMeta($block);
        if (null === $callId) {
            return;
        }
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            $this->toolCallIdByCallId[$callId] = $block->id;

            return;
        }
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            $list = $this->toolResultIdsByCallId[$callId] ?? [];
            if (!\in_array($block->id, $list, true)) {
                $list[] = $block->id;
                $this->toolResultIdsByCallId[$callId] = $list;
            }
        }
    }

    private function unindexToolSide(TranscriptBlock $block): void
    {
        $callId = $this->toolCallIdMeta($block);
        if (null === $callId) {
            return;
        }
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            if (($this->toolCallIdByCallId[$callId] ?? null) === $block->id) {
                unset($this->toolCallIdByCallId[$callId]);
            }

            return;
        }
        if (TranscriptBlockKindEnum::ToolResult === $block->kind) {
            $list = $this->toolResultIdsByCallId[$callId] ?? [];
            $list = array_values(array_filter($list, static fn (string $id): bool => $id !== $block->id));
            if ([] === $list) {
                unset($this->toolResultIdsByCallId[$callId]);
            } else {
                $this->toolResultIdsByCallId[$callId] = $list;
            }
        }
    }

    private function toolCallIdMeta(TranscriptBlock $block): ?string
    {
        $callId = $block->meta['tool_call_id'] ?? null;

        return \is_string($callId) && '' !== $callId ? $callId : null;
    }

    private function isKnownBlock(string $id): bool
    {
        return isset($this->blocksById[$id]);
    }

    private function isTailBlockId(string $id): bool
    {
        if ([] === $this->blockOrder) {
            return true;
        }

        return $this->blockOrder[\count($this->blockOrder) - 1] === $id;
    }

    /**
     * Mid-list removal is only "safe" when the block is not a tool exchange participant
     * that would require re-scoring other results. Prefer full reproject otherwise.
     */
    private function isSafeMidRemoval(string $id): bool
    {
        $block = $this->blocksById[$id] ?? null;
        if (null === $block) {
            return true;
        }

        // Tool call/result mid-removal can change pairing — exceptional full path.
        return !\in_array($block->kind, [
            TranscriptBlockKindEnum::ToolCall,
            TranscriptBlockKindEnum::ToolResult,
        ], true);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, true>
     */
    private function expandDependencyIds(array $ids): array
    {
        $expanded = [];
        foreach ($ids as $id) {
            $idx = $this->blockIndex[$id] ?? null;
            if (null === $idx) {
                continue;
            }
            $expanded[$id] = true;
            if ($idx > 0) {
                $expanded[$this->blockOrder[$idx - 1]] = true;
            }
            if ($idx + 1 < \count($this->blockOrder)) {
                $expanded[$this->blockOrder[$idx + 1]] = true;
            }
        }

        return $expanded;
    }

    private function fullReproject(): TranscriptVisualPatch
    {
        $revision = $this->presentationRevision();
        if ([] === $this->blockOrder) {
            $welcome = $this->welcomeNode($revision);
            $this->nodesByKey = [$welcome->key => $welcome];
            $this->nodeOrder = [$welcome->key];
            $this->visualKeyByPrimaryId = [];

            return TranscriptVisualPatch::full([$welcome]);
        }

        $toolResultsByCallId = $this->buildToolResultsByCallId();
        $consumedToolResultIds = [];
        $consumedToolCallIds = [];
        $items = [];
        $hasRenderedVisibleBlock = false;
        $visualKeyByPrimaryId = [];

        $blockCount = \count($this->blockOrder);
        for ($index = 0; $index < $blockCount; ++$index) {
            $block = $this->blocksById[$this->blockOrder[$index]];
            $nextBlock = null;
            if ($index + 1 < $blockCount) {
                $nextBlock = $this->blocksById[$this->blockOrder[$index + 1]];
            }

            if ($this->factory->isTranscriptWidgetSuppressed($block)) {
                continue;
            }
            if ($this->factory->shouldSuppressEmptyAssistantPlaceholder($block, $nextBlock)) {
                continue;
            }
            if (TranscriptBlockKindEnum::ToolResult === $block->kind
                && $this->factory->shouldSkipStandaloneToolResultInList($block, $consumedToolCallIds)) {
                continue;
            }

            if ($hasRenderedVisibleBlock && TranscriptBlockKindEnum::UserMessage === $block->kind) {
                $sepKey = 'sep-before:'.$block->id;
                $items[] = new TranscriptVisualNode(
                    key: $sepKey,
                    kind: TranscriptVisualNode::KIND_SEPARATOR,
                    primary: null,
                    secondary: null,
                    presentationRevision: $revision,
                );
            }

            $matchedToolResult = null;
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $matchedToolResult = $this->factory->findCombinableToolResultForCall(
                    $block,
                    $toolResultsByCallId,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
            }

            if (null !== $matchedToolResult) {
                $this->factory->markToolResultConsumedForExchange(
                    $matchedToolResult,
                    $consumedToolResultIds,
                    $consumedToolCallIds,
                );
                $key = $this->stableToolVisualKey($block);
                $items[] = new TranscriptVisualNode(
                    key: $key,
                    kind: TranscriptVisualNode::KIND_TOOL_EXCHANGE,
                    primary: $block,
                    secondary: $matchedToolResult,
                    presentationRevision: $revision,
                );
                $visualKeyByPrimaryId[$block->id] = $key;
                $visualKeyByPrimaryId[$matchedToolResult->id] = $key;
            } elseif (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $key = $this->stableToolVisualKey($block);
                $items[] = new TranscriptVisualNode(
                    key: $key,
                    kind: $this->classifyStandalone($block),
                    primary: $block,
                    secondary: null,
                    presentationRevision: $revision,
                );
                $visualKeyByPrimaryId[$block->id] = $key;
            } else {
                $key = $block->id;
                $items[] = new TranscriptVisualNode(
                    key: $key,
                    kind: $this->classifyStandalone($block),
                    primary: $block,
                    secondary: null,
                    presentationRevision: $revision,
                );
                $visualKeyByPrimaryId[$block->id] = $key;
            }

            $hasRenderedVisibleBlock = true;
        }

        if ([] === $items) {
            $welcome = $this->welcomeNode($revision);
            $this->nodesByKey = [$welcome->key => $welcome];
            $this->nodeOrder = [$welcome->key];
            $this->visualKeyByPrimaryId = [];

            return TranscriptVisualPatch::full([$welcome]);
        }

        $this->nodesByKey = [];
        $this->nodeOrder = [];
        foreach ($items as $item) {
            $this->nodesByKey[$item->key] = $item;
            $this->nodeOrder[] = $item->key;
        }
        $this->visualKeyByPrimaryId = $visualKeyByPrimaryId;

        return TranscriptVisualPatch::full($items);
    }

    /**
     * Reproject only the dependency-bounded set of primary block IDs into visual keys.
     *
     * @param list<string> $affectedPrimaryIds     Expanded set (neighbors + dirty)
     * @param list<string> $structuralCandidateIds Originally dirty IDs (not neighbors)
     */
    private function reprojectAffected(
        array $affectedPrimaryIds,
        array $structuralCandidateIds,
    ): TranscriptVisualPatch {
        // Structural decision is based on originally dirty IDs only. Neighbor expansion
        // for separators must not force O(B) reproject on pure Error/stream content updates.
        $needsStructuralScan = false;
        foreach ($structuralCandidateIds as $id) {
            $block = $this->blocksById[$id] ?? null;
            if (null === $block) {
                // Removal: separators / pairing may drop — exceptional structural path.
                $needsStructuralScan = true;
                break;
            }
            if (TranscriptBlockKindEnum::UserMessage === $block->kind
                || TranscriptBlockKindEnum::Question === $block->kind
                || TranscriptBlockKindEnum::ToolCall === $block->kind
                || TranscriptBlockKindEnum::ToolResult === $block->kind
            ) {
                $needsStructuralScan = true;
                break;
            }
        }

        if ($needsStructuralScan) {
            // ponytail: structural policy (tool/question/user/separator) full-reprojects
            // O(B) then diffs to a bounded mounted patch when survivor order is stable.
            // Ceiling: large sessions with frequent tool/question structure churn.
            // Upgrade: per-exchange/neighbor dependency graph if profiling shows this
            // structural scan matters. Pure stream/content never enters this branch.
            $beforeKeys = $this->nodeOrder;
            $beforeNodes = $this->nodesByKey;
            $full = $this->fullReproject();

            // Convert full snapshot into an incremental patch of only changed keys
            // when relative order of survivors is preserved and only tail-ish keys moved.
            return $this->diffToIncrementalPatch($beforeKeys, $beforeNodes, $full);
        }

        // Pure in-place content updates (streaming markdown / generic / thinking).
        // Removals are always structural (classified before this branch); a missing
        // block here is an invariant violation → explicit full reproject.
        $revision = $this->presentationRevision();
        $upserts = [];
        foreach ($affectedPrimaryIds as $id) {
            $block = $this->blocksById[$id] ?? null;
            if (null === $block) {
                return $this->fullReproject();
            }

            if ($this->factory->isTranscriptWidgetSuppressed($block)) {
                continue;
            }

            $key = $this->visualKeyByPrimaryId[$id] ?? $id;
            $kind = $this->classifyStandalone($block);
            $node = new TranscriptVisualNode(
                key: $key,
                kind: $kind,
                primary: $block,
                secondary: null,
                presentationRevision: $revision,
            );

            $existing = $this->nodesByKey[$key] ?? null;
            if (null !== $existing && $existing->sameSources($node)) {
                continue;
            }

            if (null === $existing) {
                // Unexpected new key on pure content path — full reproject.
                return $this->fullReproject();
            }

            $this->nodesByKey[$key] = $node;
            $this->visualKeyByPrimaryId[$id] = $key;
            $upserts[] = $node;
        }

        if ([] === $this->nodeOrder) {
            return $this->fullReproject();
        }

        // Content-only: keyed upserts only — mounted path is O(changes).
        return TranscriptVisualPatch::content($upserts);
    }

    /**
     * After an internal full reproject, emit only keys that actually changed so the
     * reconciler and tests see a bounded operation scope for ordinary structural tails
     * (tool result arrival, single append) when survivor order is stable.
     *
     * @param list<string>                        $beforeKeys
     * @param array<string, TranscriptVisualNode> $beforeNodes
     */
    private function diffToIncrementalPatch(array $beforeKeys, array $beforeNodes, TranscriptVisualPatch $full): TranscriptVisualPatch
    {
        $afterKeys = $full->order;
        $afterNodes = $this->nodesByKey;

        // Relative order of shared keys must match for incremental apply.
        $beforeSet = array_fill_keys($beforeKeys, true);
        $afterSet = array_fill_keys($afterKeys, true);
        $prevSurv = [];
        foreach ($beforeKeys as $k) {
            if (isset($afterSet[$k])) {
                $prevSurv[] = $k;
            }
        }
        $afterSurv = [];
        foreach ($afterKeys as $k) {
            if (isset($beforeSet[$k])) {
                $afterSurv[] = $k;
            }
        }
        if ($prevSurv !== $afterSurv) {
            return $full;
        }

        // Non-tail insertion → full outer resync path.
        $seenNew = false;
        foreach ($afterKeys as $k) {
            $isNew = !isset($beforeSet[$k]);
            if ($isNew) {
                $seenNew = true;
                continue;
            }
            if ($seenNew) {
                return $full;
            }
        }

        $upserts = [];
        $removals = [];
        foreach ($beforeKeys as $k) {
            if (!isset($afterSet[$k])) {
                $removals[] = $k;
            }
        }
        foreach ($afterKeys as $k) {
            $after = $afterNodes[$k];
            $before = $beforeNodes[$k] ?? null;
            if (null === $before || !$before->sameSources($after)) {
                $upserts[] = $after;
            }
        }

        return TranscriptVisualPatch::structural($upserts, $removals, $afterKeys);
    }

    /**
     * @return array<string, list<TranscriptBlock>>
     */
    private function buildToolResultsByCallId(): array
    {
        $index = [];
        foreach ($this->toolResultIdsByCallId as $callId => $ids) {
            foreach ($ids as $id) {
                if (isset($this->blocksById[$id])) {
                    $index[$callId][] = $this->blocksById[$id];
                }
            }
        }

        return $index;
    }

    private function welcomeNode(int $revision): TranscriptVisualNode
    {
        return new TranscriptVisualNode(
            key: self::WELCOME_KEY,
            kind: TranscriptVisualNode::KIND_WELCOME,
            primary: null,
            secondary: null,
            presentationRevision: $revision,
        );
    }

    private function classifyStandalone(TranscriptBlock $block): string
    {
        if ($this->factory->subagentRenderer()->supports($block)) {
            return TranscriptVisualNode::KIND_SUBAGENT;
        }

        if (TranscriptBlockKindEnum::Question === $block->kind) {
            return TranscriptVisualNode::KIND_QUESTION;
        }

        if ($this->isMarkdownVisual($block)) {
            return TranscriptVisualNode::KIND_MARKDOWN;
        }

        // Pending tool call (no combinable result yet) is still tool-exchange lifecycle.
        if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
            return TranscriptVisualNode::KIND_TOOL_EXCHANGE;
        }

        return TranscriptVisualNode::KIND_GENERIC;
    }

    /**
     * Stable presentation key for a tool call and its eventual paired exchange.
     *
     * Invariant: canonical projection writes one ToolCall block per non-empty
     * tool_call_id (`tool_call_<id>` via ToolProjectionSubscriber). Identity is
     * that id; block id is only a degenerate fallback if meta is empty.
     */
    private function stableToolVisualKey(TranscriptBlock $callBlock): string
    {
        $callId = $callBlock->meta['tool_call_id'] ?? null;
        if (\is_string($callId) && '' !== $callId) {
            return 'exchange:'.$callId;
        }

        return 'exchange:'.$callBlock->id;
    }

    private function isMarkdownVisual(TranscriptBlock $block): bool
    {
        if (\in_array($block->kind, [
            TranscriptBlockKindEnum::UserMessage,
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::AssistantThinking,
        ], true)) {
            return true;
        }

        return TranscriptBlockKindEnum::System === $block->kind
            && 'markdown' === ($block->meta['style'] ?? null);
    }
}
