<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Minimum keyed adapter from visual patches to Symfony ContainerWidget children.
 *
 * Presentation policy and order decisions live in {@see TranscriptVisualProjector}.
 * This class only maps stable visual keys to mounted widgets and applies patches:
 * content (in-place), structural (remove + tail append), full (clear + ordered re-add).
 *
 * Symfony owns render caching. Semantic widget {@see apply()} is data binding only.
 */
final class TranscriptMountedWidget extends ContainerWidget
{
    private readonly TranscriptVisualProjector $projector;

    /**
     * Stable visual key → mounted widget (identity + O(1) lookup).
     *
     * @var array<string, AbstractWidget>
     */
    private array $nodes = [];

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
        // Modest vertical rhythm between distinct transcript nodes (not every wrapped line).
        $this->setStyle(new Style(direction: Direction::Vertical, gap: 1));
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
     * Full replacement path: bootstrap, resume, history position, preview invalidation,
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
        if ($patch->isFull()) {
            $this->reconcileFull($patch);

            return;
        }

        if ($patch->isContentOnly()) {
            $this->reconcileContentOnly($patch);

            return;
        }

        $this->reconcileStructural($patch);
    }

    /**
     * Content-only: keyed upserts in O(changes). Projector already filtered unchanged sources.
     * Missing key or incompatible mid-list replacement falls back to explicit full snapshot.
     */
    private function reconcileContentOnly(TranscriptVisualPatch $patch): void
    {
        foreach ($patch->upserts as $node) {
            $existing = $this->nodes[$node->key] ?? null;
            if (null === $existing) {
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }

            $bound = $this->bind($existing, $node);
            if ($bound !== $existing) {
                // ContainerWidget cannot splice a mid-list child replacement.
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }
        }
    }

    /**
     * Structural: projector guarantees survivor relative order and that new keys are tail appends.
     * Remove gone keys, update existing, append new. No order revalidation here.
     */
    private function reconcileStructural(TranscriptVisualPatch $patch): void
    {
        foreach ($patch->removals as $key) {
            $this->detachKey($key);
        }

        foreach ($patch->upserts as $node) {
            $existing = $this->nodes[$node->key] ?? null;
            if (null === $existing) {
                $widget = $this->createAndBind($node);
                $this->add($widget);
                $this->nodes[$node->key] = $widget;
                continue;
            }

            $bound = $this->bind($existing, $node);
            if ($bound !== $existing) {
                // Kind/shape change on an existing key cannot be spliced mid-list.
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }
        }
    }

    /**
     * Exceptional full path: clear container and re-add in supplied order.
     * Reuses keyed widget objects when kind-compatible; O(B) is correct here.
     */
    private function reconcileFull(TranscriptVisualPatch $patch): void
    {
        $order = $patch->order ?? [];
        $desired = [];
        foreach ($patch->nodes as $node) {
            $desired[$node->key] = $node;
        }

        $previous = $this->nodes;
        $next = [];
        foreach ($order as $key) {
            $node = $desired[$key] ?? null;
            if (null === $node) {
                continue;
            }
            $existing = $previous[$key] ?? null;
            $next[$key] = null === $existing
                ? $this->createAndBind($node)
                : $this->bind($existing, $node);
        }

        $this->clear();
        foreach ($order as $key) {
            if (isset($next[$key])) {
                $this->add($next[$key]);
            }
        }
        $this->nodes = $next;
    }

    private function detachKey(string $key): void
    {
        $widget = $this->nodes[$key] ?? null;
        if (null !== $widget) {
            $this->remove($widget);
        }
        unset($this->nodes[$key]);
    }

    /**
     * Update a compatible widget in place, or return a replacement for full re-add.
     */
    private function bind(AbstractWidget $widget, TranscriptVisualNode $node): AbstractWidget
    {
        if ($widget instanceof MutableTranscriptWidget) {
            if (!$widget->canBind($node)) {
                return $this->createAndBind($node);
            }
            $widget->apply($node);

            return $widget;
        }

        if ($widget instanceof WelcomeTranscriptWidget) {
            return TranscriptVisualNode::KIND_WELCOME === $node->kind
                ? $widget
                : $this->createAndBind($node);
        }

        if ($widget instanceof TurnSeparatorWidget) {
            return TranscriptVisualNode::KIND_SEPARATOR === $node->kind
                ? $widget
                : $this->createAndBind($node);
        }

        // Generic TextWidget: mutate in place so mid-list content updates keep order.
        // Theme color is already baked into factory text via ANSI escapes.
        if ($widget instanceof TextWidget && TranscriptVisualNode::KIND_GENERIC === $node->kind) {
            $fresh = $this->createWidgetFor($node);
            if ($fresh instanceof TextWidget) {
                $widget->setText($fresh->getText());
                $style = $fresh->getStyle();
                if (null !== $style) {
                    $widget->setStyle($style);
                }

                return $widget;
            }

            return $fresh;
        }

        return $this->createAndBind($node);
    }

    private function createAndBind(TranscriptVisualNode $node): AbstractWidget
    {
        $widget = $this->createWidgetFor($node);
        if ($widget instanceof MutableTranscriptWidget) {
            $widget->apply($node);
        }

        return $widget;
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
}
