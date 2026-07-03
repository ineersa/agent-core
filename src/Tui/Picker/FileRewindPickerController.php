<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
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

final class FileRewindPickerController
{
    private ?PickerOverlay $overlay = null;
    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;
    private ?string $sessionId = null;
    private ?TextWidget $headerWidget = null;

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
        $targets = $this->restorableTargets($sessionId, $tree);
        if ([] === $targets) {
            $this->screen->setStatus('rewind', 'No file rewind checkpoints are available yet.');
            $this->tui->requestRender();

            return;
        }
        $this->openTurnPicker($targets);
    }

    public function closePicker(bool $requestRender = true): void
    {
        $this->overlay?->close($requestRender);
        $this->overlay = null;
        $this->headerWidget = null;
        $this->screen?->setStatus('rewind', null);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * @param list<array{turnNo:int,title:string}> $targets
     */
    private function openTurnPicker(array $targets): void
    {
        $tui = $this->tui;
        $screen = $this->screen;
        if (null === $tui || null === $screen) {
            return;
        }
        $theme = $screen->theme();
        $this->headerWidget = new TextWidget(text: $theme->muted('File rewind — select checkpoint (Esc to close)'), truncate: true);
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
        $selected = max(0, \count($targets) - 1);
        $items = $this->buildCheckpointItems($targets, $theme, $selected);
        $list = new SelectListWidget(items: $items, maxVisible: 10, keybindings: $kb);
        $list->setSelectedIndex($selected);
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
        $list->onSelectionChange(static function (SelectionChangeEvent $event) use ($picker, $list, $targets, $theme): void {
            $turnNo = (int) $event->getItem()['value'];
            $selectedIdx = 0;
            foreach ($targets as $i => $target) {
                if ($target['turnNo'] === $turnNo) {
                    $selectedIdx = $i;
                    break;
                }
            }
            $list->setItems($picker->buildCheckpointItems($targets, $theme, $selectedIdx));
            $list->setSelectedIndex(max(0, $selectedIdx));
            $picker->updateHeaderForTurn($turnNo, $targets[$selectedIdx]['title'] ?? ('Turn '.$turnNo));
        });
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $list, $this->headerWidget);
        $initial = $targets[$selected];
        $this->updateHeaderForTurn($initial['turnNo'], $initial['title']);
    }

    private function updateHeaderForTurn(int $turnNo, string $title): void
    {
        if (null === $this->headerWidget || null === $this->screen) {
            return;
        }
        $label = mb_strimwidth($title, 0, 60, '…');
        $this->headerWidget->setText($this->screen->theme()->muted('Checkpoint turn '.$turnNo.': '.$label.' (Esc to close)'));
        $this->screen->setStatus('rewind', null);
        $this->tui?->requestRender();
    }

    /**
     * @param list<array{turnNo:int,title:string}> $targets
     *
     * @return list<array{value:string,label:string}>
     */
    private function buildCheckpointItems(array $targets, TuiTheme $theme, int $selectedIndex): array
    {
        $items = [];
        foreach ($targets as $idx => $target) {
            $turnNo = $target['turnNo'];
            $title = mb_strimwidth($target['title'], 0, 60, '…');
            $marker = $idx === $selectedIndex ? '◉ ' : '○ ';
            $label = $marker.'Turn '.$turnNo.': '.$title;
            if ($idx === $selectedIndex) {
                $label = $theme->color(ThemeColorEnum::Accent, $label);
            }
            $items[] = ['value' => (string) $turnNo, 'label' => $label];
        }

        return $items;
    }

    /**
     * @return list<array{turnNo:int,title:string}>
     */
    private function restorableTargets(string $sessionId, TurnTreeView $tree): array
    {
        $targets = [];
        foreach (TreePickerController::flattenTurnOrder($tree) as $turnNo) {
            if (!$this->previewPort->hasCheckpoint($sessionId, $turnNo)) {
                continue;
            }
            $node = $tree->nodesByTurnNo[$turnNo] ?? null;
            if (!$node instanceof TurnTreeNodeView) {
                continue;
            }
            $title = trim($node->title);
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                $title = trim($node->promptPreview);
            }
            if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
                continue;
            }
            $targets[] = ['turnNo' => $turnNo, 'title' => $title];
        }

        return $targets;
    }

    private function openActionPicker(string $sessionId, int $turnNo): void
    {
        if (null === $this->tui || null === $this->screen) {
            return;
        }
        if (!$this->previewPort->hasCheckpoint($sessionId, $turnNo)) {
            $this->screen->setStatus('rewind', 'Selected checkpoint is no longer available.');
            $this->tui->requestRender();

            return;
        }
        $theme = $this->screen->theme();
        $items = [
            ['value' => 'restore_files', 'label' => 'Restore files to this turn'],
            ['value' => 'restore_files_and_conversation', 'label' => 'Restore files + conversation rewind'],
        ];
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
        $list = new SelectListWidget(items: $items, maxVisible: 4, keybindings: $kb);
        $this->headerWidget = new TextWidget(text: $theme->muted('Turn '.$turnNo.' — choose action (Esc to close)'), truncate: true);
        $picker = $this;
        $actionPort = $this->actionPort;
        $list->onSelect(static function (SelectEvent $event) use ($picker, $sessionId, $turnNo, $actionPort): void {
            $picker->closePicker();
            try {
                $actionPort->execute($sessionId, $turnNo, (string) $event->getItem()['value']);
                $picker->screen?->setStatus('rewind', null);
            } catch (\Throwable $e) {
                $picker->screen?->setStatus('rewind', 'File rewind failed: '.$e->getMessage());
            }
            $picker->tui?->requestRender();
        });
        $list->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });
        $this->overlay = new PickerOverlay();
        $this->overlay->mount($this->tui, $this->screen, $list, $this->headerWidget);
    }
}
