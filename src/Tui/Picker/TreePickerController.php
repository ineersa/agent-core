<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
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
 * Opens a read-only SelectListWidget showing the current session's
 * turn tree with indentation, branch structure, and a current-leaf
 * marker. Entering a turn closes the picker without mutating state.
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
     * Open the turn tree picker as a read-only overlay.
     *
     * Fetches the tree from the provider, builds flat items, and
     * mounts a SelectListWidget via PickerOverlay.  If the session
     * has no events, a status message is shown instead.
     *
     * Enter closes the picker (read-only); Escape/Ctrl+C cancels.
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
            text: $screen->theme()->muted('Session turn tree — read-only (Esc to close)'),
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
        $items = self::buildItems($tree, $theme, selectedIndex: 0);
        // Pre-compute the depth-first order of turn numbers for selection-change indexing
        $flattenedOrder = self::flattenTurnOrder($tree);

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        // ── Arrows → rebuild items so newly selected row gets accent colour ──
        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, $tree, $theme, $flattenedOrder): void {
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
            },
        );

        // ── Enter → close only (read-only) ──
        $picker = $this;
        $listWidget->onSelect(static function (SelectEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        // ── Escape / Ctrl+C → close without change ──
        $listWidget->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        // ── Mount via PickerOverlay ──
        $this->overlay = new PickerOverlay();
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
     * Build flat picker items from a turn tree.
     *
     * Depth-first walk, producing indented labels with leaf markers.
     * Public and static for testability.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function buildItems(TurnTreeView $tree, TuiTheme $theme, int $selectedIndex = -1): array
    {
        $items = [];
        $visited = [];

        foreach ($tree->rootTurnNos as $rootTurnNo) {
            self::walkNode($rootTurnNo, $tree->nodesByTurnNo, 0, $items, $visited, $tree->currentLeafTurnNo, $theme, $selectedIndex, 0);
        }

        return $items;
    }

    /**
     * Pre-compute the depth-first order of turn numbers (for selection-change indexing).
     *
     * @return list<int>
     */
    public static function flattenTurnOrder(TurnTreeView $tree): array
    {
        $order = [];
        $visited = [];

        foreach ($tree->rootTurnNos as $rootTurnNo) {
            self::flattenOrderWalk($rootTurnNo, $tree->nodesByTurnNo, $order, $visited);
        }

        return $order;
    }

    // ── Private helpers ─────────────────────────────────────────────

    /**
     * @param array<int, TurnTreeNodeView>                              $nodesByTurnNo
     * @param list<array{value:string,label:string,description:string}> $items
     * @param array<int, true>                                          $visited
     */
    private static function walkNode(
        int $turnNo,
        array $nodesByTurnNo,
        int $depth,
        array &$items,
        array &$visited,
        ?int $currentLeafTurnNo,
        TuiTheme $theme,
        int $selectedIndex,
        int $currentItemCount,
    ): int {
        if (isset($visited[$turnNo])) {
            return $currentItemCount;
        }
        $visited[$turnNo] = true;

        $node = $nodesByTurnNo[$turnNo] ?? null;
        if (null === $node) {
            return $currentItemCount;
        }

        $isCurrentLeaf = $node->turnNo === $currentLeafTurnNo;
        $prefix = $isCurrentLeaf ? '◉ ' : '○ ';
        $indent = '';
        if ($depth > 0) {
            $indent = str_repeat('  ', max(0, $depth - 1)).'└─ ';
        }

        $label = $indent.$prefix.\sprintf(
            'Turn %d: %s',
            $node->turnNo,
            mb_strimwidth($node->title, 0, 60, '…'),
        );

        $description = '';
        if (null !== $node->createdAt) {
            $description = $node->createdAt->format('Y-m-d H:i');
        }

        $idx = \count($items);
        if ($idx === $selectedIndex) {
            $label = $theme->color(ThemeColorEnum::Accent, $label);
        }

        $items[] = [
            'value' => (string) $node->turnNo,
            'label' => $label,
            'description' => $description,
        ];

        $currentItemCount = $idx + 1;

        foreach ($node->childTurnNos as $childTurnNo) {
            $currentItemCount = self::walkNode($childTurnNo, $nodesByTurnNo, $depth + 1, $items, $visited, $currentLeafTurnNo, $theme, $selectedIndex, $currentItemCount);
        }

        return $currentItemCount;
    }

    /**
     * @param array<int, TurnTreeNodeView> $nodesByTurnNo
     * @param list<int>                    $order
     * @param array<int, true>             $visited
     */
    private static function flattenOrderWalk(
        int $turnNo,
        array $nodesByTurnNo,
        array &$order,
        array &$visited,
    ): void {
        if (isset($visited[$turnNo])) {
            return;
        }
        $visited[$turnNo] = true;

        $node = $nodesByTurnNo[$turnNo] ?? null;
        if (null === $node) {
            return;
        }

        $order[] = $node->turnNo;

        foreach ($node->childTurnNos as $childTurnNo) {
            self::flattenOrderWalk($childTurnNo, $nodesByTurnNo, $order, $visited);
        }
    }
}
