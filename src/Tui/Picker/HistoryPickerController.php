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
 * Manages the /history picker overlay.
 *
 * User entries position context before the prompt and load it into the editor.
 * Assistant entries position context after the answer and load the next prompt.
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

    public function open(?int $before = null, ?int $after = null): void
    {
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;

        $history = $this->historyProvider->forSession($state->sessionId, $before, $after);
        if ([] === $history->entries) {
            $screen->setTransientStatus('history', 'Session has no conversation history yet');
            $screen->refresh();

            return;
        }

        $header = new TextWidget(
            text: $screen->theme()->muted('Session history — Enter to select position (Esc to close)'),
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

        $listWidget->onSelect(static function (SelectEvent $event) use ($picker, $switcher, $history): void {
            $value = $event->getItem()['value'];
            $picker->closePicker();
            if ('older' === $value) {
                $picker->open(before: $history->olderBefore);
            } elseif ('newer' === $value) {
                $picker->open(after: $history->newerAfter);
            } elseif (str_starts_with($value, 'assistant:')) {
                $switcher->selectHistoryTurn((int) substr($value, 10), 'assistant');
            } else {
                $switcher->selectHistoryTurn((int) $value);
            }
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
        if (null !== $history->olderBefore) {
            $items[] = ['value' => 'older', 'label' => $theme->muted('Older entries...')];
        }
        foreach ($history->entries as $prompt) {
            $body = PickerListLabelFormatter::sanitizeTitle($prompt->text);
            if ('' === $body) {
                $body = ucfirst($prompt->role).' message (turn '.$prompt->turnNo.')';
            }
            $marker = $prompt->anchor === $history->selectedAnchor && $prompt->role === $history->selectedRole ? '◉ ' : '○ ';
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, $prompt->role);
            $items[] = [
                'value' => ('assistant' === $prompt->role ? 'assistant:' : '').$prompt->turnNo,
                'label' => $marker.$prefix.' '.$body,
            ];
        }
        if (null !== $history->newerAfter) {
            $items[] = ['value' => 'newer', 'label' => $theme->muted('Newer entries...')];
        }

        return $items;
    }

    /**
     * @return int<0, max>
     */
    public static function initialSelectedIndex(HistoryView $history): int
    {
        $prompts = $history->entries;
        if ([] === $prompts) {
            return 0;
        }

        // Prefer the first entry after the selected tip (tip may equal a prompt
        // turn when sitting at its completion, or an internal predecessor).
        $offset = null !== $history->olderBefore ? 1 : 0;
        foreach ($prompts as $idx => $prompt) {
            if ($prompt->anchor > $history->selectedAnchor) {
                return $idx + $offset;
            }
        }

        return max(0, \count($prompts) - 1 + $offset);
    }
}
