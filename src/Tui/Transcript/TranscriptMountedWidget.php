<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;

/**
 * Keyed mounted reconciler for the production transcript region.
 *
 * Presentation policy and retained canonical state live in
 * {@see TranscriptVisualProjector}. This class only mounts semantic/native
 * children and applies {@see TranscriptVisualPatch} values.
 *
 * Full clear()+ordered add is reserved for relative reorder / non-tail
 * insertion that Symfony ContainerWidget cannot splice.
 */
final class TranscriptMountedWidget extends ContainerWidget
{
    private readonly TranscriptVisualProjector $projector;

    /**
     * @var array<string, AbstractWidget>
     */
    private array $nodes = [];

    /**
     * Last applied visual node per key (source identity for native widgets).
     *
     * @var array<string, TranscriptVisualNode>
     */
    private array $appliedNodes = [];

    /** @var list<string> */
    private array $nodeOrder = [];

    private ?TranscriptVisualPatch $lastPatch = null;

    public function __construct(
        private readonly TuiTheme $theme,
        TranscriptDisplayConfig $displayConfig = new TranscriptDisplayConfig(),
        TranscriptDisplayState $displayState = new TranscriptDisplayState(),
    ) {
        $this->projector = new TranscriptVisualProjector(
            displayConfig: $displayConfig,
            displayState: $displayState,
        );
        $this->applyPatch($this->projector->replaceAll([]));
    }

    /**
     * Canonical blocks retained by the presentation model.
     *
     * @return list<TranscriptBlock>
     */
    public function getBlocks(): array
    {
        return $this->projector->exportBlocks();
    }

    /**
     * Last production visual patch applied by setBlocks/applyChangeSet.
     * Tests assert bounded touched keys on ordinary tail updates.
     */
    public function lastVisualPatch(): ?TranscriptVisualPatch
    {
        return $this->lastPatch;
    }

    /**
     * Full replacement path: bootstrap, resume, leaf/branch, preview invalidation,
     * non-tail insertion/reorder.
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function setBlocks(array $blocks): void
    {
        $this->applyPatch($this->projector->replaceAll($blocks));
    }

    /**
     * Incremental apply for ordinary projector deltas (tail stream/update/remove).
     */
    public function applyChangeSet(TranscriptChangeSet $changes): void
    {
        if ($changes->isEmpty() && !$changes->isFull()) {
            return;
        }

        $this->applyPatch($this->projector->applyChangeSet($changes));
    }

    private function applyPatch(TranscriptVisualPatch $patch): void
    {
        $this->lastPatch = $patch;

        if ($patch->isFull()) {
            $this->reconcileToOrder($patch->nodes, $patch->order, fullSnapshot: true);

            return;
        }

        $this->reconcileIncremental($patch);
    }

    private function reconcileIncremental(TranscriptVisualPatch $patch): void
    {
        $desiredOrder = $patch->order;
        $desiredSet = array_fill_keys($desiredOrder, true);

        // Relative order of survivors — if changed, rebuild from upserts + retained nodes.
        $prevSurviving = [];
        foreach ($this->nodeOrder as $key) {
            if (isset($desiredSet[$key])) {
                $prevSurviving[] = $key;
            }
        }
        $desiredSurviving = [];
        $prevSet = array_fill_keys($this->nodeOrder, true);
        foreach ($desiredOrder as $key) {
            if (isset($prevSet[$key])) {
                $desiredSurviving[] = $key;
            }
        }

        $seenNew = false;
        $nonTailInsertion = false;
        foreach ($desiredOrder as $key) {
            $isNew = !isset($prevSet[$key]);
            if ($isNew) {
                $seenNew = true;
                continue;
            }
            if ($seenNew) {
                $nonTailInsertion = true;
                break;
            }
        }

        if ($prevSurviving !== $desiredSurviving || $nonTailInsertion) {
            // Build full node list: prefer upsert payloads, else retained applied nodes.
            $nodes = [];
            $upsertByKey = [];
            foreach ($patch->upserts as $node) {
                $upsertByKey[$node->key] = $node;
            }
            foreach ($desiredOrder as $key) {
                if (isset($upsertByKey[$key])) {
                    $nodes[] = $upsertByKey[$key];
                    continue;
                }
                if (isset($this->appliedNodes[$key])) {
                    $nodes[] = $this->appliedNodes[$key];
                }
            }
            $this->reconcileToOrder($nodes, $desiredOrder, fullSnapshot: true);

            return;
        }

        foreach ($patch->removals as $key) {
            $this->detachKey($key);
        }

        foreach ($patch->upserts as $node) {
            $existing = $this->nodes[$node->key] ?? null;
            if (null === $existing) {
                $widget = $this->createWidgetFor($node);
                $this->applyToWidget($widget, $node);
                $this->add($widget);
                $this->nodes[$node->key] = $widget;
                $this->appliedNodes[$node->key] = $node;
                continue;
            }

            $this->applyToWidget($existing, $node);
            $this->appliedNodes[$node->key] = $node;
        }

        $this->nodeOrder = $desiredOrder;
    }

    /**
     * @param list<TranscriptVisualNode> $desired
     * @param list<string>               $desiredOrder
     */
    private function reconcileToOrder(array $desired, array $desiredOrder, bool $fullSnapshot): void
    {
        $desiredByKey = [];
        foreach ($desired as $item) {
            $desiredByKey[$item->key] = $item;
        }

        $previousOrder = $this->nodeOrder;
        $previousNodes = $this->nodes;
        $previousApplied = $this->appliedNodes;

        $previousSet = array_fill_keys($previousOrder, true);
        $desiredSet = array_fill_keys($desiredOrder, true);

        $previousSurviving = [];
        foreach ($previousOrder as $key) {
            if (isset($desiredSet[$key])) {
                $previousSurviving[] = $key;
            }
        }
        $desiredSurviving = [];
        foreach ($desiredOrder as $key) {
            if (isset($previousSet[$key])) {
                $desiredSurviving[] = $key;
            }
        }

        $seenNew = false;
        $needsOuterResync = $previousSurviving !== $desiredSurviving;
        if (!$needsOuterResync) {
            foreach ($desiredOrder as $key) {
                $isNew = !isset($previousSet[$key]);
                if ($isNew) {
                    $seenNew = true;
                    continue;
                }
                if ($seenNew) {
                    $needsOuterResync = true;
                    break;
                }
            }
        }

        if ($needsOuterResync || ([] === $previousOrder && $fullSnapshot)) {
            $nextNodes = [];
            $nextApplied = [];
            foreach ($desiredOrder as $key) {
                $item = $desiredByKey[$key] ?? null;
                if (null === $item) {
                    continue;
                }
                $existing = $previousNodes[$key] ?? null;
                if (null === $existing) {
                    $widget = $this->createWidgetFor($item);
                    $this->applyToWidget($widget, $item);
                } else {
                    $this->applyToWidget($existing, $item);
                    $widget = $existing;
                }
                $nextNodes[$key] = $widget;
                $nextApplied[$key] = $item;
            }

            $this->clear();
            foreach ($desiredOrder as $key) {
                if (isset($nextNodes[$key])) {
                    $this->add($nextNodes[$key]);
                }
            }
            $this->nodes = $nextNodes;
            $this->appliedNodes = $nextApplied;
            $this->nodeOrder = $desiredOrder;

            return;
        }

        // Granular: remove gone keys, append new tail, update existing.
        foreach ($previousOrder as $key) {
            if (!isset($desiredSet[$key])) {
                $this->detachKey($key);
            }
        }

        $nextNodes = [];
        $nextApplied = [];
        foreach ($desiredOrder as $key) {
            $item = $desiredByKey[$key] ?? $previousApplied[$key] ?? null;
            if (null === $item) {
                continue;
            }
            $existing = $this->nodes[$key] ?? $previousNodes[$key] ?? null;
            if (null === $existing) {
                $widget = $this->createWidgetFor($item);
                $this->applyToWidget($widget, $item);
                $this->add($widget);
            } else {
                $this->applyToWidget($existing, $item);
                $widget = $existing;
            }
            $nextNodes[$key] = $widget;
            $nextApplied[$key] = $item;
        }

        $this->nodes = $nextNodes;
        $this->appliedNodes = $nextApplied;
        $this->nodeOrder = $desiredOrder;
    }

    private function detachKey(string $key): void
    {
        $widget = $this->nodes[$key] ?? null;
        if (null !== $widget) {
            $this->remove($widget);
        }
        unset($this->nodes[$key], $this->appliedNodes[$key]);
    }

    private function createWidgetFor(TranscriptVisualNode $node): AbstractWidget
    {
        $factory = $this->projector->factory();

        return match ($node->kind) {
            TranscriptVisualNode::KIND_WELCOME => new WelcomeTranscriptWidget($this->theme),
            TranscriptVisualNode::KIND_SEPARATOR => new TurnSeparatorWidget($this->theme),
            TranscriptVisualNode::KIND_MARKDOWN => new StreamingMarkdownTranscriptWidget($factory, $this->theme),
            TranscriptVisualNode::KIND_TOOL_EXCHANGE => new ToolExchangeTranscriptWidget($factory, $this->theme),
            TranscriptVisualNode::KIND_QUESTION => new QuestionTranscriptWidget($factory, $this->theme),
            TranscriptVisualNode::KIND_SUBAGENT => new SubagentTranscriptWidget($factory, $this->theme),
            default => $factory->buildWidget(
                $node->primary ?? throw new \LogicException('Generic visual node missing primary block.'),
                $this->theme,
            ),
        };
    }

    private function applyToWidget(AbstractWidget $widget, TranscriptVisualNode $node): void
    {
        if ($widget instanceof StreamingMarkdownTranscriptWidget
            || $widget instanceof ToolExchangeTranscriptWidget
            || $widget instanceof QuestionTranscriptWidget
            || $widget instanceof SubagentTranscriptWidget
        ) {
            $widget->apply($node);

            return;
        }

        // Native static widgets (TextWidget/Welcome/Separator): replace only when sources change.
        $previous = $this->appliedNodes[$node->key] ?? null;
        if (null !== $previous && $previous->sameSources($node)) {
            return;
        }

        // Trivial static node with no apply API — recreate by swapping parent child.
        // Callers that need content updates on KIND_GENERIC go through createWidgetFor again
        // in reconcile when the widget is replaced.
        if ($widget instanceof WelcomeTranscriptWidget || $widget instanceof TurnSeparatorWidget) {
            return;
        }

        // Generic TextWidget: rebuild by removing and re-adding a fresh instance at same key.
        // Parent ContainerWidget has no replace API; detach + recreate is handled by caller
        // when identity is wrong. Here we only no-op if sameSources; else force recreate:
        if (null !== $previous && !$previous->sameSources($node)) {
            // Mark for recreate: remove and put new widget in nodes map at call site.
            // applyToWidget is only invoked when widget already matches key; recreate happens
            // via createWidgetFor when existing is null. For source change on generic, swap:
            $fresh = $this->createWidgetFor($node);
            $this->remove($widget);
            $this->add($fresh);
            $this->nodes[$node->key] = $fresh;
        }
    }
}
