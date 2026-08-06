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
 * Manages the /history picker overlay (linear user-prompt undo/redo).
 *
 * Rows are user prompts only. Selecting prompt N rewinds conversation context
 * to immediately before N and populates the editor with N's original text.
 * Forward history remains until a context-mutating action discards it.
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

    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

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
        if ([] === self::flattenTurnOrder($tree)) {
            $screen->setStatus('history', 'Session has no user prompts yet');
            $screen->refresh();

            return;
        }

        $header = new TextWidget(
            text: $screen->theme()->muted('Session history — Enter to edit prompt (Esc to close)'),
            truncate: true,
        );

        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_page_up' => [Key::PAGE_UP],
            'select_page_down' => [Key::PAGE_DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);

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

        $picker = $this;
        $switcher = $this->switcher;

        $listWidget->onSelect(static function (SelectEvent $event) use ($picker, $switcher): void {
            $turnNo = (int) $event->getItem()['value'];
            $picker->closePicker();
            $switcher->rewindToTurn($turnNo);
        });

        $listWidget->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
    }

    public function overlay(): ?PickerOverlay
    {
        return $this->overlay;
    }

    /**
     * Build picker items: user prompts only, linear order.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(TurnTreeView $tree, TuiTheme $theme): array
    {
        return self::walkUserPrompts($tree, $theme)[0];
    }

    /**
     * @return list<int>
     */
    public static function flattenTurnOrder(TurnTreeView $tree): array
    {
        return self::walkUserPrompts($tree)[1];
    }

    /**
     * @return int<0, max>
     */
    public static function initialSelectedIndex(TurnTreeView $tree): int
    {
        $order = self::flattenTurnOrder($tree);
        if ([] === $order) {
            return 0;
        }

        // Cursor sits at the retained tip; highlight the next user prompt after tip
        // when positioned before a prompt (undo cursor), else the last user row.
        $tip = $tree->currentLeafTurnNo;
        if (null === $tip) {
            return 0;
        }

        // Prefer the first user prompt whose turnNo is strictly after tip
        // (context is before that prompt). Fall back to last row at/after tip.
        foreach ($order as $idx => $turnNo) {
            if ($turnNo > $tip) {
                return max(0, $idx);
            }
        }

        return max(0, \count($order) - 1);
    }

    /**
     * @return array{0: list<array{value:string,label:string}>, 1: list<int>}
     */
    private static function walkUserPrompts(TurnTreeView $tree, ?TuiTheme $theme = null): array
    {
        $items = [];
        $order = [];

        foreach ($tree->activePathTurnNos as $turnNo) {
            $node = $tree->nodesByTurnNo[$turnNo] ?? null;
            if (!$node instanceof TurnTreeNodeView) {
                continue;
            }
            if ('user' !== $node->displayRole) {
                continue;
            }

            if (null !== $theme) {
                $body = PickerListLabelFormatter::sanitizeTitle($node->title);
                if ('' === $body || preg_match('/^Turn \d+$/', $body)) {
                    $body = PickerListLabelFormatter::sanitizeTitle($node->promptPreview);
                }
                if ('' === $body || preg_match('/^Turn \d+$/', $body)) {
                    $body = 'User message (turn '.$node->turnNo.')';
                }
                $marker = $node->isCurrentLeaf ? '◉ ' : '○ ';
                $prefix = PickerListLabelFormatter::formatRolePrefix($theme, 'user');
                $label = $marker.$prefix.' '.$body;
                $items[] = [
                    'value' => (string) $node->turnNo,
                    'label' => $label,
                ];
            }

            $order[] = $node->turnNo;
        }

        return [$items, $order];
    }
}
