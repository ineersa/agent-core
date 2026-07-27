<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Keyed mounted reconciler for the production transcript region.
 *
 * Presentation policy lives in {@see TranscriptVisualProjector}. This class only
 * mounts {@see SemanticTranscriptNodeWidget} children and keeps wrapper identity
 * across ordinary append/update/remove. Full clear()+ordered add is reserved for
 * relative reorder / non-tail insertion that Symfony ContainerWidget cannot splice.
 *
 * Dirty detection uses immutable source object identity + presentation revision
 * (preview expansion). Symfony owns render caching — no app-level line caches.
 */
final class TranscriptMountedWidget extends ContainerWidget
{
    private const string OUTER_RESYNC_REASON_RELATIVE_ORDER = 'relative_order_changed';
    private const string OUTER_RESYNC_REASON_NON_TAIL_INSERTION = 'non_tail_insertion';

    /** @var list<TranscriptBlock> */
    private array $blocks = [];

    private readonly TranscriptVisualProjector $projector;

    /**
     * @var array<string, SemanticTranscriptNodeWidget>
     */
    private array $nodes = [];

    /** @var list<string> */
    private array $nodeOrder = [];

    public function __construct(
        private readonly TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
        $this->projector = new TranscriptVisualProjector(
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
        $this->reconcile();
    }

    /** @return list<TranscriptBlock> */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /**
     * Full replacement path: bootstrap, resume, leaf/branch, preview invalidation,
     * non-tail insertion/reorder.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        // Avoid array_values() copy when already a packed list.
        $this->blocks = $blocks;
        $this->reconcile();
    }

    /**
     * Incremental apply for ordinary projector deltas (tail stream/update/remove).
     *
     * Falls back to full reproject when the delta cannot be applied safely without
     * reordering (explicit full mode, or removals/upserts that need policy re-run
     * across the whole list for tool pairing/suppression). Presentation policy is
     * re-run over the retained block list — not a dual renderer.
     */
    public function applyChangeSet(TranscriptChangeSet $changes): void
    {
        if ($changes->isFull()) {
            $this->setBlocks($changes->blocks());

            return;
        }

        if ($changes->isEmpty()) {
            return;
        }

        // Apply to local block list with object-identity upserts.
        $indexById = [];
        foreach ($this->blocks as $idx => $block) {
            $indexById[$block->id] = $idx;
        }

        foreach ($changes->removals as $id) {
            $idx = $indexById[$id] ?? null;
            if (null === $idx) {
                continue;
            }
            array_splice($this->blocks, $idx, 1);
            // Rebuild map after splice for subsequent removals in the same batch.
            $indexById = [];
            foreach ($this->blocks as $i => $block) {
                $indexById[$block->id] = $i;
            }
        }

        foreach ($changes->upserts as $block) {
            $idx = $indexById[$block->id] ?? null;
            if (null === $idx) {
                $indexById[$block->id] = \count($this->blocks);
                $this->blocks[] = $block;
                continue;
            }
            $this->blocks[$idx] = $block;
        }

        // Tool pairing / empty-assistant / separator policy depends on neighbors;
        // reproject from the retained list. Full-history text hashing is gone —
        // dirty detection is object identity on projected nodes.
        $this->reconcile();
    }

    private function reconcile(): void
    {
        $desired = $this->projector->project($this->blocks);

        $desiredKeys = [];
        $desiredByKey = [];
        foreach ($desired as $item) {
            $desiredKeys[] = $item->key;
            $desiredByKey[$item->key] = $item;
        }

        $previousOrder = $this->nodeOrder;
        $previousNodes = $this->nodes;
        $outerResyncReason = $this->detectOuterResyncReason($previousOrder, $desiredKeys);

        if (null !== $outerResyncReason) {
            $this->performOuterResync($desired, $previousNodes);

            return;
        }

        foreach ($previousOrder as $existingKey) {
            if (isset($desiredByKey[$existingKey])) {
                continue;
            }
            $node = $previousNodes[$existingKey] ?? null;
            if (null !== $node) {
                $this->remove($node);
                unset($this->nodes[$existingKey]);
            }
        }
        $this->nodeOrder = array_values(array_filter(
            $this->nodeOrder,
            static fn (string $key): bool => isset($desiredByKey[$key]),
        ));

        $nextNodes = [];
        $nextOrder = [];
        $factory = $this->projector->factory();
        foreach ($desired as $item) {
            $key = $item->key;
            $existing = $this->nodes[$key] ?? null;
            if (null === $existing) {
                $wrapper = new SemanticTranscriptNodeWidget($factory, $this->theme);
                $wrapper->apply($item);
                $this->add($wrapper);
                $nextNodes[$key] = $wrapper;
                $nextOrder[] = $key;
                continue;
            }

            $existing->apply($item);
            $nextNodes[$key] = $existing;
            $nextOrder[] = $key;
        }

        $this->nodes = $nextNodes;
        $this->nodeOrder = $nextOrder;
    }

    /**
     * @param list<string> $previousOrder
     * @param list<string> $desiredKeys
     */
    private function detectOuterResyncReason(array $previousOrder, array $desiredKeys): ?string
    {
        if ([] === $previousOrder) {
            return null;
        }

        $previousSet = array_fill_keys($previousOrder, true);
        $desiredSet = array_fill_keys($desiredKeys, true);

        $previousSurviving = [];
        foreach ($previousOrder as $key) {
            if (isset($desiredSet[$key])) {
                $previousSurviving[] = $key;
            }
        }

        $desiredSurviving = [];
        foreach ($desiredKeys as $key) {
            if (isset($previousSet[$key])) {
                $desiredSurviving[] = $key;
            }
        }

        if ($previousSurviving !== $desiredSurviving) {
            return self::OUTER_RESYNC_REASON_RELATIVE_ORDER;
        }

        $seenNew = false;
        foreach ($desiredKeys as $key) {
            $isNew = !isset($previousSet[$key]);
            if ($isNew) {
                $seenNew = true;
                continue;
            }
            if ($seenNew) {
                return self::OUTER_RESYNC_REASON_NON_TAIL_INSERTION;
            }
        }

        return null;
    }

    /**
     * @param list<TranscriptVisualNode>                  $desired
     * @param array<string, SemanticTranscriptNodeWidget> $previousNodes
     */
    private function performOuterResync(array $desired, array $previousNodes): void
    {
        $nextNodes = [];
        $nextOrder = [];
        $factory = $this->projector->factory();
        foreach ($desired as $item) {
            $key = $item->key;
            $existing = $previousNodes[$key] ?? null;
            if (null === $existing) {
                $wrapper = new SemanticTranscriptNodeWidget($factory, $this->theme);
                $wrapper->apply($item);
            } else {
                $existing->apply($item);
                $wrapper = $existing;
            }

            $nextNodes[$key] = $wrapper;
            $nextOrder[] = $key;
        }

        $this->clear();
        foreach ($nextOrder as $key) {
            $this->add($nextNodes[$key]);
        }

        $this->nodes = $nextNodes;
        $this->nodeOrder = $nextOrder;
    }
}
