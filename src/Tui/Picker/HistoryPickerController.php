<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Manages the /history picker overlay (linear user-prompt undo/redo).
 *
 * Rows are human prompts only. Selecting prompt N positions conversation context
 * immediately before N and populates the editor with N's original text.
 * Forward history remains until a context-mutating action discards it.
 */
final class HistoryPickerController
{
    private ?PickerOverlay $overlay = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly ChatScreen $screen,
        private readonly TuiSessionState $state,
        private readonly HistoryProviderInterface $historyProvider,
        private readonly TuiSessionSwitchServiceInterface $switcher,
    ) {
    }

    public function open(): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        $history = $this->historyProvider->forSession($state->sessionId);
        if ([] === $history->prompts) {
            $screen->setStatus('history', 'Session has no user prompts yet');
            $screen->refresh();

            return;
        }

        $header = new TextWidget(
            text: $screen->theme()->muted('Session history — Enter to edit prompt (Esc to close)'),
            truncate: true,
        );

        $kb = SelectListKeybindings::standard();

        $theme = $screen->theme();
        $initialSelectedIndex = self::initialSelectedIndex($history);
        $items = self::buildItems($history, $theme);

        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: SelectListKeybindings::MAX_VISIBLE,
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

    
    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
    }

    
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(HistoryView $history, TuiTheme $theme): array
    {
        $items = [];
        $tip = $history->positionTurnNo;
        foreach ($history->prompts as $prompt) {
            $body = PickerListLabelFormatter::sanitizeTitle($prompt->promptText);
            if ('' === $body) {
                $body = 'User message (turn '.$prompt->turnNo.')';
            }
            $marker = $prompt->turnNo === $tip ? '◉ ' : '○ ';
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, 'user');
            $items[] = [
                'value' => (string) $prompt->turnNo,
                'label' => $marker.$prefix.' '.$body,
            ];
        }

        return $items;
    }

    /**
     * @return int<0, max>
     */
    public static function initialSelectedIndex(HistoryView $history): int
    {
        $prompts = $history->prompts;
        if ([] === $prompts) {
            return 0;
        }

        // Prefer the first human prompt after the selected tip (tip may equal a prompt
        // turn when sitting at its completion, or an internal predecessor).
        $tip = $history->positionTurnNo;
        foreach ($prompts as $idx => $prompt) {
            if ($prompt->turnNo > $tip) {
                return $idx;
            }
        }

        return max(0, \count($prompts) - 1);
    }
}
