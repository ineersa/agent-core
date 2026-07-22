<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the turn tree picker overlay lifecycle.
 *
 * Opens a SelectListWidget showing the current session's turn tree with
 * tree connectors at branch points only (└─/├─/│), and a current-leaf
 * marker (◉). Entering a turn rewinds the session to that turn (actionable);
 * selecting the current leaf is a no-op (just closes).
 * Escape/Ctrl+C cancels.
 *
 * Tree data is rebuilt from canonical events.jsonl on each open
 * (no caching), so the picker always reflects the latest session state.
 */
final class TreePickerController
{
    private ?PickerOverlay $overlay = null;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly TurnTreeProviderInterface $treeProvider,
        private readonly TuiSessionSwitchServiceInterface $switcher,
    ) {
    }

    /**
     * Set per-run TUI references (called by TreeCommandRegistrar).
     */
    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

    /**
     * Open the turn tree picker as an actionable overlay.
     *
     * Fetches the tree from the provider, builds flat items, and
     * mounts a SelectListWidget via PickerOverlay.  If the session
     * has no events, a status message is shown instead.
     *
     * Enter rewinds to the selected turn; Escape/Ctrl+C cancels.
     */
    public function open(): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        if (null === $this->tui || null === $this->screen || null === $this->state) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        $tree = $this->treeProvider->forSession($state->sessionId);

        if ([] === $tree->nodesByTurnNo) {
            $screen->setStatus('tree', 'Session has no turns yet');
            $screen->refresh();

            return;
        }

        // ── Header ──
        $header = new TextWidget(
            text: $screen->theme()->muted('Session turn tree — Enter to rewind (Esc to close)'),
            truncate: true,
        );

        // ── Keybindings ──
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

        // ── Build items (static labels; SelectListWidget highlights selection) ──
        $theme = $screen->theme();
        $initialSelectedIndex = self::initialSelectedIndex($tree);
        $items = self::buildItems($tree, $theme);

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );
        $listWidget->setSelectedIndex(max(0, $initialSelectedIndex));

        $this->overlay = new PickerOverlay();

        // ── Enter → rewind (or no-op if current leaf) ──
        $picker = $this;
        $switcher = $this->switcher;
        $currentLeafTurnNo = $tree->currentLeafTurnNo;

        $listWidget->onSelect(static function (SelectEvent $event) use ($picker, $switcher, $currentLeafTurnNo): void {
            $turnNo = (int) $event->getItem()['value'];
            $picker->closePicker();

            // Selecting the current leaf is a no-op (just close).
            if ($turnNo === $currentLeafTurnNo) {
                return;
            }

            $switcher->rewindToTurn($turnNo);
        });

        // ── Escape / Ctrl+C → close without change ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    /**
     * Whether the picker is currently visible.
     */
    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * Close the picker overlay.
     */
    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
    }

    /**
     * The currently mounted PickerOverlay, or null if not mounted.
     *
     * Provides access to the underlying SelectListWidget for
     * programmatic inspection or testing.
     */
    public function overlay(): ?PickerOverlay
    {
        return $this->overlay;
    }

    /**
     * Build flat picker items from a turn tree.
     *
     * Depth-first walk, producing indented labels with leaf markers.
     * Public and static for testability.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(TurnTreeView $tree, TuiTheme $theme): array
    {
        return self::walk($tree, $theme)[0];
    }

    /**
     * Pre-compute the depth-first order of turn numbers (for selection-change indexing).
     *
     * Delegates to the unified walk to guarantee index alignment with buildItems.
     *
     * @return list<int>
     */
    public static function flattenTurnOrder(TurnTreeView $tree): array
    {
        return self::walk($tree)[1];
    }

    /**
     * Index of the current leaf turn in the depth-first picker order,
     * for initial cursor placement when the tree picker opens.
     *
     * @return int<0, max> clamped to >= 0; 0 when the current leaf is not in the order
     */
    public static function initialSelectedIndex(TurnTreeView $tree): int
    {
        $idx = array_search($tree->currentLeafTurnNo, self::flattenTurnOrder($tree), true);

        return false === $idx ? 0 : max(0, $idx);
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * Unified depth-first walk producing both items and turn order.
     *
     * When a TuiTheme is provided, items (full labels with branch-stack
     * connectors at branch points only, leaf markers, truncation, accent)
     * are built. The turn-order list
     * is always produced, guaranteeing index alignment between
     * buildItems() and flattenTurnOrder().
     *
     * @return array{0: list<array{value:string,label:string}>, 1: list<int>}
     */
    private static function walk(TurnTreeView $tree, ?TuiTheme $theme = null): array
    {
        $items = [];
        $order = [];
        $visited = [];
        $creationRankByTurnNo = self::creationRankByTurnNo($tree->nodesByTurnNo);

        foreach ($tree->rootTurnNos as $rootTurnNo) {
            self::walkNode(
                $rootTurnNo,
                $tree->nodesByTurnNo,
                $creationRankByTurnNo,
                [],
                $items,
                $order,
                $visited,
                $theme,
            );
        }

        return [$items, $order];
    }

    /**
     * Rank every node by canonical creation order: ascending anchorSeq, then turnNo.
     *
     * Sparse turn identities (max(lastSeq, turnNo)+1) are not ordinal depths, so
     * "flat continuation" cannot mean childTurnNo === parentTurnNo + 1. Creation-rank
     * adjacency is the stable substitute: an only-child is flat iff nothing else in
     * the whole tree was created between parent and child.
     *
     * @param array<int, TurnTreeNodeView> $nodesByTurnNo
     *
     * @return array<int, int> turnNo => 0-based creation rank
     */
    private static function creationRankByTurnNo(array $nodesByTurnNo): array
    {
        $nodes = array_values($nodesByTurnNo);
        usort(
            $nodes,
            static function (TurnTreeNodeView $a, TurnTreeNodeView $b): int {
                if ($a->anchorSeq !== $b->anchorSeq) {
                    return $a->anchorSeq <=> $b->anchorSeq;
                }

                return $a->turnNo <=> $b->turnNo;
            },
        );

        $ranks = [];
        foreach ($nodes as $rank => $node) {
            $ranks[$node->turnNo] = $rank;
        }

        return $ranks;
    }

    /**
     * @param array<int, TurnTreeNodeView>           $nodesByTurnNo
     * @param array<int, int>                        $creationRankByTurnNo turnNo => 0-based creation rank
     * @param list<bool>                             $branchStack          Each entry is true if that ancestor is the last child of its parent (guide column uses space vs │)
     * @param bool                                   $isContinuation       When true, this node is a single-child continuation: render guide only, no fork glyph
     * @param list<array{value:string,label:string}> $items
     * @param list<int>                              $order
     * @param array<int, true>                       $visited
     */
    private static function walkNode(
        int $turnNo,
        array $nodesByTurnNo,
        array $creationRankByTurnNo,
        array $branchStack,
        array &$items,
        array &$order,
        array &$visited,
        ?TuiTheme $theme,
        bool $isContinuation = false,
    ): void {
        if (isset($visited[$turnNo])) {
            return;
        }
        $visited[$turnNo] = true;

        $node = $nodesByTurnNo[$turnNo] ?? null;
        if (null === $node) {
            return;
        }

        if (null !== $theme) {
            // Build prefix from branch-stack: ancestor connectors + own connector
            $nodePrefix = '';
            $numLevels = \count($branchStack);
            for ($k = 0; $k < $numLevels - 1; ++$k) {
                $nodePrefix .= $branchStack[$k] ? '   ' : '│  ';
            }
            if ($numLevels >= 1) {
                $last = $numLevels - 1;
                if ($isContinuation) {
                    // Single-child continuation: guide column only (no fork glyph).
                    $nodePrefix .= $branchStack[$last] ? '   ' : '│  ';
                } else {
                    $nodePrefix .= $branchStack[$last] ? '└─ ' : '├─ ';
                }
            }

            $leafMarker = $node->isCurrentLeaf ? '◉ ' : '○ ';
            $body = PickerListLabelFormatter::sanitizeTitle($node->title);
            if ('' === $body || preg_match('/^Turn \d+$/', $body)) {
                $body = PickerListLabelFormatter::sanitizeTitle($node->promptPreview);
            }
            if ('' === $body || preg_match('/^Turn \d+$/', $body)) {
                $body = 'Turn '.$node->turnNo;
            }
            $role = $node->displayRole;
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, $role);
            $label = $nodePrefix.$leafMarker.$prefix.' '.$body;

            $items[] = [
                'value' => (string) $node->turnNo,
                'label' => $label,
            ];
        }

        $order[] = $node->turnNo;

        $childCount = \count($node->childTurnNos);
        $parentRank = $creationRankByTurnNo[$turnNo] ?? null;

        foreach ($node->childTurnNos as $ci => $childTurnNo) {
            // Flat continuation: the ONLY child AND creation-order adjacent to the parent.
            // Creation rank is derived from anchorSeq (tie-break turnNo), not turn identity
            // arithmetic. Sparse linear parent→only child with no node created between them
            // stays flat even when childTurnNo != parentTurnNo + 1. A lone rewind branch where
            // another turn was created globally between parent and child must fork with ├─/└─
            // even as an only-child, so it reads as a child rather than a sibling. A fork point
            // (2+ children) always indents every child.
            $childRank = $creationRankByTurnNo[$childTurnNo] ?? null;
            $isCreationAdjacent = null !== $parentRank
                && null !== $childRank
                && $childRank === $parentRank + 1;
            $childIsContinuation = 1 === $childCount && $isCreationAdjacent;
            $childPushesLevel = !$childIsContinuation;

            $childStack = $childPushesLevel
                ? [...$branchStack, $ci === $childCount - 1]
                : $branchStack;

            self::walkNode(
                $childTurnNo,
                $nodesByTurnNo,
                $creationRankByTurnNo,
                $childStack,
                $items,
                $order,
                $visited,
                $theme,
                $childIsContinuation,
            );
        }
    }
}
