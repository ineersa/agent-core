<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class FileRewindPickerController
{
    private ?PickerOverlay $overlay = null;
    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;
    private ?string $sessionId = null;

    public function __construct(
        private readonly TurnTreeProviderInterface $treeProvider,
        private readonly FileRewindTurnPreviewPortInterface $previewPort,
        private readonly FileRewindTurnActionPortInterface $actionPort,
    ) {
    }

    public function setRuntimeRefs(Tui $tui, ChatScreen $screen, TuiSessionState $state): void
    {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
    }

    public function open(string $sessionId): void
    {
        $this->sessionId = $sessionId;
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }
        if (null === $this->tui || null === $this->screen || null === $this->state) {
            return;
        }
        $tree = $this->treeProvider->forSession($sessionId);
        if ([] === $tree->nodesByTurnNo) {
            $this->screen->setStatus('rewind', 'Session has no turns yet');
            $this->screen->refresh();

            return;
        }
        $this->openTurnPicker($tree);
    }

    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
    }

    private function openTurnPicker($tree): void
    {
        $tui = $this->tui;
        $screen = $this->screen;
        $theme = $screen->theme();
        $header = new TextWidget(text: $theme->muted('File rewind — select turn (Esc to close)'), truncate: true);
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
        $items = TreePickerController::buildItems($tree, $theme, selectedIndex: TreePickerController::initialSelectedIndex($tree));
        $list = new SelectListWidget(items: $items, maxVisible: 10, keybindings: $kb);
        $picker = $this;
        $sessionId = (string) $this->sessionId;
        $list->onSelect(static function (SelectEvent $event) use ($picker, $sessionId): void {
            $turnNo = (int) $event->getItem()['value'];
            $picker->closePicker();
            $picker->openActionPicker($sessionId, $turnNo);
        });
        $list->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $list, $header);
        $screen->setStatus('rewind', 'Select a turn, then choose restore action');
        $screen->refresh();
    }

    private function openActionPicker(string $sessionId, int $turnNo): void
    {
        if (null === $this->tui || null === $this->screen) {
            return;
        }
        $theme = $this->screen->theme();
        $items = [
            ['value' => 'restore_files', 'label' => 'Restore files to this turn'],
            ['value' => 'restore_files_and_conversation', 'label' => 'Restore files + conversation rewind'],
            ['value' => 'conversation_only', 'label' => 'Conversation rewind only'],
            ['value' => 'undo_last_restore', 'label' => 'Undo last file restore'],
            ['value' => 'cancel', 'label' => 'Cancel'],
        ];
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
        $list = new SelectListWidget(items: $items, maxVisible: 8, keybindings: $kb);
        $header = new TextWidget(text: $theme->muted('Turn '.$turnNo.' — choose action'), truncate: true);
        $picker = $this;
        $actionPort = $this->actionPort;
        $list->onSelect(static function (SelectEvent $event) use ($picker, $sessionId, $turnNo, $actionPort): void {
            $picker->closePicker();
            try {
                $actionPort->execute($sessionId, $turnNo, (string) $event->getItem()['value']);
                $picker->screen?->setStatus('rewind', 'Action completed');
            } catch (\Throwable $e) {
                $picker->screen?->setStatus('rewind', 'File rewind failed: '.$e->getMessage());
            }
            $picker->screen?->refresh();
        });
        $list->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($this->tui, $this->screen, $list, $header);
    }
}
