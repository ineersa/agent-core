<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Minimum keyed adapter from visual patches to Symfony ContainerWidget children.
 *
 * Presentation policy and order decisions live in {@see TranscriptVisualProjector}.
 * This class maps stable visual keys to mounted widgets and applies patches:
 * content (in-place), structural (remove + tail append), full (clear + ordered re-add).
 *
 * Only the latest {@see RENDERED_ROW_BUDGET} wrapped transcript rows stay
 * mounted. Older widgets are never created. Canonical projector state remains
 * full-history. Boundary nodes that would exceed the budget are clipped to
 * their trailing rows via {@see TranscriptClippedRowsWidget}.
 *
 * Symfony owns render caching. Semantic widget {@see apply()} is data binding only.
 */
final class TranscriptMountedWidget extends ContainerWidget
{
    public const int RENDERED_ROW_BUDGET = 2000;

    private readonly TranscriptVisualProjector $projector;

    /**
     * Stable visual key → mounted widget (identity + O(1) lookup).
     *
     * @var array<string, AbstractWidget>
     */
    private array $nodes = [];

    /**
     * Stable visual key → retained node for remount after resize/trim.
     *
     * @var array<string, TranscriptVisualNode>
     */
    private array $retainedNodes = [];

    /** @var list<string> */
    private array $retainedOrder = [];

    private ?int $mountedBudgetColumns = null;

    private ?int $mountedBudgetRows = null;

    private bool $pendingMount = false;

    private bool $pendingContentDirty = false;

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

    public function beforeRender(): void
    {
        $context = $this->getContext();
        if (null === $context) {
            return;
        }

        $columns = max(1, $context->getTerminalColumns());
        $rows = max(1, $context->getTerminalRows());
        $geometryChanged = null === $this->mountedBudgetColumns
            || null === $this->mountedBudgetRows
            || $columns !== $this->mountedBudgetColumns
            || $rows !== $this->mountedBudgetRows;

        if (!$this->pendingMount && !$geometryChanged) {
            return;
        }

        $this->mountTailForGeometry($columns, $rows);
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
            if (!isset($this->retainedNodes[$node->key])) {
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }

            $this->retainedNodes[$node->key] = $node;

            $existing = $this->nodes[$node->key] ?? null;
            if (null === $existing) {
                // Outside the currently mounted tail; remount lazily with geometry.
                $this->pendingMount = true;
                $this->pendingContentDirty = true;
                continue;
            }

            if ($existing instanceof TranscriptClippedRowsWidget) {
                $this->pendingMount = true;
                $this->pendingContentDirty = true;
                continue;
            }

            $bound = $this->bind($existing, $node);
            if ($bound !== $existing) {
                // ContainerWidget cannot splice a mid-list child replacement.
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }
        }

        $this->scheduleMount();
    }

    /**
     * Structural: projector guarantees survivor relative order and that new keys are tail appends.
     * Remove gone keys, update existing, append new. No order revalidation here.
     */
    private function reconcileStructural(TranscriptVisualPatch $patch): void
    {
        foreach ($patch->removals as $key) {
            $this->detachKey($key);
            unset($this->retainedNodes[$key]);
        }

        if (null !== $patch->order) {
            $this->retainedOrder = $patch->order;
            $nextRetained = [];
            foreach ($patch->order as $key) {
                if (isset($this->retainedNodes[$key])) {
                    $nextRetained[$key] = $this->retainedNodes[$key];
                }
            }
            $this->retainedNodes = $nextRetained;
        }

        foreach ($patch->upserts as $node) {
            $this->retainedNodes[$node->key] = $node;

            $existing = $this->nodes[$node->key] ?? null;
            if (null === $existing) {
                continue;
            }

            if ($existing instanceof TranscriptClippedRowsWidget) {
                $this->pendingContentDirty = true;
                continue;
            }

            $bound = $this->bind($existing, $node);
            if ($bound !== $existing) {
                // Kind/shape change on an existing key cannot be spliced mid-list.
                $this->applyPatch($this->projector->replaceAll($this->projector->exportBlocks()));

                return;
            }
        }

        $this->scheduleMount();
    }

    /**
     * Exceptional full path: retain projector order, then remount only the
     * budgeted tail under real terminal geometry.
     */
    private function reconcileFull(TranscriptVisualPatch $patch): void
    {
        $order = $patch->order ?? [];
        $desired = [];
        foreach ($patch->nodes as $node) {
            $desired[$node->key] = $node;
        }

        $this->retainedOrder = $order;
        $this->retainedNodes = $desired;
        $this->pendingContentDirty = true;
        $this->scheduleMount();
    }

    private function scheduleMount(): void
    {
        $context = $this->getContext();
        if (null === $context) {
            // Stay lazy until attached with terminal geometry. Detached
            // measurement would miss live theme stylesheets and unbounded
            // mounting defeats the rendered-row budget.
            $this->pendingMount = true;

            return;
        }

        $this->mountTailForGeometry(
            max(1, $context->getTerminalColumns()),
            max(1, $context->getTerminalRows()),
        );
    }

    private function mountTailForGeometry(int $columns, int $rows): void
    {
        $plan = $this->planMountedTail($columns, $rows);
        $desiredKeys = $plan['keys'];

        if (
            !$this->pendingContentDirty
            && $columns === $this->mountedBudgetColumns
            && $rows === $this->mountedBudgetRows
            && $desiredKeys === array_keys($this->nodes)
            && !$this->pendingMount
        ) {
            // Membership and geometry unchanged: keep mounted widgets/caches.
            return;
        }

        $previous = $this->nodes;
        $next = [];
        $boundaryKey = $plan['boundaryKey'];
        $boundaryKeep = $plan['boundaryKeep'];

        foreach ($desiredKeys as $key) {
            $node = $this->retainedNodes[$key] ?? null;
            if (null === $node) {
                continue;
            }

            $existing = $previous[$key] ?? null;

            if ($key === $boundaryKey && $boundaryKeep > 0) {
                $widget = $this->createClippedBoundaryWidget($node, $key, $boundaryKeep, $columns, $rows);
            } elseif (
                null !== $existing
                && !$existing instanceof TranscriptClippedRowsWidget
                && !$this->pendingContentDirty
            ) {
                $widget = $this->bind($existing, $node);
                if ($widget !== $existing) {
                    // Incompatible reuse: build fresh instead of splicing mid-list.
                    $widget = $this->createAndBind($node);
                }
            } else {
                $widget = null === $existing || $existing instanceof TranscriptClippedRowsWidget
                    ? $this->createAndBind($node)
                    : $this->bind($existing, $node);
                if (
                    null !== $existing
                    && !$existing instanceof TranscriptClippedRowsWidget
                    && $widget !== $existing
                ) {
                    $widget = $this->createAndBind($node);
                }
            }
            $next[$key] = $widget;
        }

        // Attach before any measurement/render cache write. Detached renders resolve
        // Markdown/subagent style elements through DefaultStyleSheet and would cache
        // unthemed rows across the later attach (attach does not invalidate).
        if ($desiredKeys !== array_keys($this->nodes) || array_values($next) !== array_values($this->nodes)) {
            $this->clear();
            foreach ($desiredKeys as $key) {
                if (isset($next[$key])) {
                    $this->add($next[$key]);
                }
            }
        }

        foreach (array_keys($previous) as $key) {
            if (!isset($next[$key])) {
                unset($previous[$key]);
            }
        }

        $this->nodes = $next;
        $this->mountedBudgetColumns = $columns;
        $this->mountedBudgetRows = $rows;
        $this->pendingMount = false;
        $this->pendingContentDirty = false;
    }

    /**
     * @return array{
     *     keys: list<string>,
     *     boundaryKey: ?string,
     *     boundaryKeep: int
     * }
     */
    private function planMountedTail(int $columns, int $rows): array
    {
        $gap = $this->getStyle()?->getGap() ?? 0;
        $selectedKeys = [];
        $usedRows = 0;
        $boundaryKey = null;
        $boundaryKeep = 0;

        for ($i = \count($this->retainedOrder) - 1; $i >= 0; --$i) {
            $key = $this->retainedOrder[$i];
            $node = $this->retainedNodes[$key] ?? null;
            if (null === $node) {
                continue;
            }

            $existing = $this->nodes[$key] ?? null;
            if (
                null !== $existing
                && !$existing instanceof TranscriptClippedRowsWidget
                && !$this->pendingContentDirty
            ) {
                $rowCount = $this->measureWidgetRows($existing, $columns, $rows);
            } else {
                // Measure from a temporary widget; do not leave it mounted.
                $probe = $this->createAndBind($node);
                $rowCount = $this->withAttachedProbe(
                    $probe,
                    fn (AbstractWidget $attached): int => $this->measureWidgetRows($attached, $columns, $rows),
                );
            }

            $extraGap = [] === $selectedKeys ? 0 : $gap;
            $projected = $usedRows + $extraGap + $rowCount;

            if ($projected > self::RENDERED_ROW_BUDGET) {
                $remaining = self::RENDERED_ROW_BUDGET - $usedRows - $extraGap;
                if ($remaining > 0) {
                    $selectedKeys[] = $key;
                    $usedRows += $extraGap + $remaining;
                    $boundaryKey = $key;
                    $boundaryKeep = $remaining;
                } elseif ([] === $selectedKeys) {
                    // Hard budget: keep only the trailing budget rows of this node.
                    $selectedKeys[] = $key;
                    $usedRows = self::RENDERED_ROW_BUDGET;
                    $boundaryKey = $key;
                    $boundaryKeep = self::RENDERED_ROW_BUDGET;
                }
                break;
            }

            $selectedKeys[] = $key;
            $usedRows = $projected;
        }

        return [
            'keys' => array_reverse($selectedKeys),
            'boundaryKey' => $boundaryKey,
            'boundaryKeep' => $boundaryKeep,
        ];
    }

    private function createClippedBoundaryWidget(
        TranscriptVisualNode $node,
        string $key,
        int $boundaryKeep,
        int $columns,
        int $rows,
    ): TranscriptClippedRowsWidget {
        $source = $this->createAndBind($node);
        $lines = $this->withAttachedProbe(
            $source,
            fn (AbstractWidget $attached): array => $this->renderWidgetLines($attached, $columns, $rows),
        );
        $kept = array_values(\array_slice($lines, -$boundaryKeep));

        return new TranscriptClippedRowsWidget(
            lines: $kept,
        );
    }

    /**
     * Attach a temporary probe under the live transcript context, then detach it.
     *
     * Style elements (Markdown headings, subagent card borders) resolve through
     * WidgetContext stylesheets only while attached. Detached widgets fall back to
     * DefaultStyleSheet. Probes are never left in the mounted child list.
     *
     * @template T
     *
     * @param callable(AbstractWidget): T $callback
     *
     * @return T
     */
    private function withAttachedProbe(AbstractWidget $widget, callable $callback): mixed
    {
        $context = $this->getContext();
        if (null === $context) {
            throw new \LogicException('Transcript mount requires an attached widget context.');
        }

        if (null !== $widget->getContext()) {
            return $callback($widget);
        }

        $context->attachChild($this, $widget);
        try {
            return $callback($widget);
        } finally {
            if ($widget->getContext() === $context) {
                $context->detachChild($widget);
            }
        }
    }

    private function measureWidgetRows(AbstractWidget $widget, int $columns, int $rows): int
    {
        return \count($this->renderWidgetLines($widget, $columns, $rows));
    }

    /**
     * @return list<string>
     */
    private function renderWidgetLines(AbstractWidget $widget, int $columns, int $rows): array
    {
        $context = $this->getContext();
        if (null === $context) {
            throw new \LogicException('Transcript mount requires an attached widget context.');
        }
        if (null === $widget->getContext()) {
            throw new \LogicException('Cannot measure a detached transcript widget; attach it first so theme stylesheets apply.');
        }

        $cacheRows = max(1, $rows);
        $cached = $widget->getRenderCache($columns, $cacheRows);
        if (null !== $cached) {
            return $cached;
        }

        return $context->renderWidget(
            $widget,
            new RenderContext($columns, $cacheRows),
        );
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
