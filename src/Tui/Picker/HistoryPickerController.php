<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
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
 * Rows are user prompts only. Selecting prompt N positions conversation context
 * immediately before N and populates the editor with N's original text.
 * Forward history remains until a context-mutating action discards it.
 */
final class HistoryPickerController
{
    private ?PickerOverlay $overlay = null;

    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly HistoryProviderInterface $historyProvider,
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

        $history = $this->historyProvider->forSession($state->sessionId);
        if ([] === self::userPromptTurnNos($history)) {
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
        $initialSelectedIndex = self::initialSelectedIndex($history);
        $items = self::buildItems($history, $theme);

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
            $switcher->selectHistoryTurn($turnNo);
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
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(HistoryView $history, TuiTheme $theme): array
    {
        $items = [];
        foreach (self::userPromptRows($history) as $turn) {
            $body = PickerListLabelFormatter::sanitizeTitle($turn->title);
            if ('' === $body || preg_match('/^Turn \d+$/', $body)) {
                $body = 'User message (turn '.$turn->turnNo.')';
            }
            $marker = $turn->isPosition ? '◉ ' : '○ ';
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, 'user');
            $items[] = [
                'value' => (string) $turn->turnNo,
                'label' => $marker.$prefix.' '.$body,
            ];
        }

        return $items;
    }

    /**
     * @return list<int>
     */
    public static function userPromptTurnNos(HistoryView $history): array
    {
        return array_map(
            static fn (HistoryPromptView $turn): int => $turn->turnNo,
            self::userPromptRows($history),
        );
    }

    /**
     * @return int<0, max>
     */
    public static function initialSelectedIndex(HistoryView $history): int
    {
        $order = self::userPromptTurnNos($history);
        if ([] === $order) {
            return 0;
        }

        $tip = $history->positionTurnNo;
        if (null === $tip) {
            return 0;
        }

        foreach ($order as $idx => $turnNo) {
            if ($turnNo > $tip) {
                return max(0, $idx);
            }
        }

        return max(0, \count($order) - 1);
    }

    /**
     * @return list<HistoryPromptView>
     */
    private static function userPromptRows(HistoryView $history): array
    {
        $rows = [];
        foreach ($history->turns as $turn) {
            if ('user' !== $turn->displayRole) {
                continue;
            }
            $rows[] = $turn;
        }

        return $rows;
    }
}
