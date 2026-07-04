<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
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

        // ── Build items ──
        $theme = $screen->theme();
        // Pre-compute the depth-first order of turn numbers for selection-change indexing
        $flattenedOrder = self::flattenTurnOrder($tree);
        $initialSelectedIndex = self::initialSelectedIndex($tree);
        $items = self::buildItems($tree, $theme, selectedIndex: $initialSelectedIndex);

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );
        $listWidget->setSelectedIndex(max(0, $initialSelectedIndex));

        // ── Mount shell (handlers need overlay ref for post-navigation repaint) ──
        $this->overlay = new PickerOverlay();

        // ── Arrows → rebuild items so newly selected row gets accent colour ──
        // setSelectedIndex() does NOT re-dispatch SelectionChangeEvent
        // (verified in SelectListWidget.php), so calling it after
        // setItems() is safe and does not cause infinite recursion.
        $overlayRef = $this->overlay;
        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, $tree, $theme, $flattenedOrder, $tui, $overlayRef): void {
                $selectedValue = $event->getItem()['value'];
                $selectedIdx = -1;

                foreach ($flattenedOrder as $i => $turnNo) {
                    if ((string) $turnNo === $selectedValue) {
                        $selectedIdx = $i;

                        break;
                    }
                }

                $newItems = self::buildItems($tree, $theme, selectedIndex: $selectedIdx);
                $listWidget->setItems($newItems);
                $listWidget->setSelectedIndex(max(0, $selectedIdx));
                $overlayRef->invalidateListPaint($tui);
            },
        );

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

        $this->overlay->mount(
            $tui,
            $screen,
            $listWidget,
            $header,
            PickerOverlayPlacementEnum::BeforeEditor,
        );
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
    public static function buildItems(TurnTreeView $tree, TuiTheme $theme, int $selectedIndex = -1): array
    {
        return self::walk($tree, $theme, $selectedIndex)[0];
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
    private static function walk(TurnTreeView $tree, ?TuiTheme $theme = null, int $selectedIndex = -1): array
    {
        $items = [];
        $order = [];
        $visited = [];

        foreach ($tree->rootTurnNos as $rootTurnNo) {
            self::walkNode($rootTurnNo, $tree->nodesByTurnNo, [], $items, $order, $visited, $theme, $selectedIndex);
        }

        return [$items, $order];
    }

    /**
     * @param array<int, TurnTreeNodeView>           $nodesByTurnNo
     * @param list<bool>                             $branchStack    Each entry is true if that ancestor is the last child of its parent (guide column uses space vs │)
     * @param bool                                   $isContinuation When true, this node is a single-child continuation: render guide only, no fork glyph
     * @param list<array{value:string,label:string}> $items
     * @param list<int>                              $order
     * @param array<int, true>                       $visited
     */
    private static function walkNode(
        int $turnNo,
        array $nodesByTurnNo,
        array $branchStack,
        array &$items,
        array &$order,
        array &$visited,
        ?TuiTheme $theme,
        int $selectedIndex,
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
            [$body, $fallbackRole] = PickerListLabelFormatter::bodyAndRoleFromNodeTitle(
                $node->title,
                $node->promptPreview,
                $node->turnNo,
            );
            $role = '' !== $node->displayRole ? $node->displayRole : $fallbackRole;
            $body = mb_strimwidth(PickerListLabelFormatter::sanitizeTitle($body), 0, 52, '…');
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, $role);
            $label = $nodePrefix.$leafMarker.$prefix.' '.$body;

            $idx = \count($items);
            if ($idx === $selectedIndex) {
                $label = $theme->color(ThemeColorEnum::Accent, $label);
            }

            $items[] = [
                'value' => (string) $node->turnNo,
                'label' => $label,
            ];
        }

        $order[] = $node->turnNo;

        $childCount = \count($node->childTurnNos);

        foreach ($node->childTurnNos as $ci => $childTurnNo) {
            // Flat continuation: the ONLY child AND a consecutive follow-up (turn_no = parent + 1).
            // A rewind branch is non-consecutive (turn_no != parent + 1) and must fork — indent
            // with ├─/└─ even when it is an only-child, so a lone branch is shown as a child,
            // not a sibling. A fork point (2+ children) always indents every child.
            $isConsecutiveFollowUp = $childTurnNo === $turnNo + 1;
            $childIsContinuation = 1 === $childCount && $isConsecutiveFollowUp;
            $childPushesLevel = !$childIsContinuation;

            $childStack = $childPushesLevel
                ? [...$branchStack, $ci === $childCount - 1]
                : $branchStack;

            self::walkNode(
                $childTurnNo,
                $nodesByTurnNo,
                $childStack,
                $items,
                $order,
                $visited,
                $theme,
                $selectedIndex,
                $childIsContinuation,
            );
        }
    }
}
