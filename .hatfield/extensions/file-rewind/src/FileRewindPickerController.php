<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
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
        private readonly FileRewindService $service,
        private readonly TurnTreeProviderInterface $treeProvider,
    ) {
    }

    public function wire(TuiRuntimeContext $context): void
    {
        $this->tui = $context->tui;
        $this->screen = $context->screen;
        $this->state = $context->state;
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
        $items = $this->buildCheckpointItems($targets, $theme);
        $list = new SelectListWidget(items: $items, maxVisible: 10, keybindings: $kb);
        $list->setSelectedIndex($selected);
        $picker = $this;
        $sessionId = (string) $this->sessionId;
        $list->onSelect(static function (SelectEvent $event) use ($picker, $sessionId): void {
            $turnNo = (int) $event->getItem()['value'];
            $picker->closePicker();
            $picker->restoreCheckpoint($sessionId, $turnNo);
        });
        $list->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });
        $list->onSelectionChange(static function (SelectionChangeEvent $event) use ($picker, $targets): void {
            $turnNo = (int) $event->getItem()['value'];
            $title = 'Turn '.$turnNo;
            foreach ($targets as $target) {
                if ($target['turnNo'] === $turnNo) {
                    $title = $target['title'];
                    break;
                }
            }
            $picker->updateHeaderForTurn($turnNo, $title);
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
     * @param list<array{turnNo:int,title:string,displayRole?:string}> $targets
     *
     * @return list<array{value:string,label:string}>
     */
    private function buildCheckpointItems(array $targets, TuiTheme $theme): array
    {
        $items = [];
        foreach ($targets as $target) {
            $turnNo = $target['turnNo'];
            $body = mb_strimwidth(PickerListLabelFormatter::sanitizeTitle($target['title']), 0, 52, '…');
            $role = \is_string($target['displayRole'] ?? null) && '' !== $target['displayRole']
                ? $target['displayRole']
                : 'assistant';
            $prefix = PickerListLabelFormatter::formatRolePrefix($theme, $role);
            $label = $prefix.' checkpoint '.$turnNo.': '.$body;
            $items[] = ['value' => (string) $turnNo, 'label' => $label];
        }

        return $items;
    }

    /**
     * @return list<array{turnNo:int,title:string,displayRole:string}>
     */
    private function restorableTargets(string $sessionId, TurnTreeView $tree): array
    {
        $targets = [];
        foreach (TreePickerController::flattenTurnOrder($tree) as $turnNo) {
            if (!$this->service->hasCheckpointForTurn($sessionId, $turnNo)) {
                continue;
            }
            $node = $tree->nodesByTurnNo[$turnNo] ?? null;
            if (!$node instanceof TurnTreeNodeView) {
                continue;
            }
            $title = $this->checkpointRowTitle($node, $turnNo);
            $targets[] = ['turnNo' => $turnNo, 'title' => $title, 'displayRole' => $node->displayRole];
        }

        return $targets;
    }

    private function checkpointRowTitle(TurnTreeNodeView $node, int $turnNo): string
    {
        $title = trim($node->title);
        if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
            $title = trim($node->promptPreview);
        }
        if ('' === $title || preg_match('/^Turn \d+$/', $title)) {
            return 'Checkpoint (turn '.$turnNo.')';
        }

        return $title;
    }

    private function restoreCheckpoint(string $sessionId, int $turnNo): void
    {
        if (null === $this->screen || null === $this->tui) {
            return;
        }
        if (!$this->service->hasCheckpointForTurn($sessionId, $turnNo)) {
            $this->screen->setStatus('rewind', 'Selected checkpoint is no longer available.');
            $this->tui->requestRender();

            return;
        }
        try {
            $this->service->restoreForTurn($sessionId, $turnNo);
            $this->screen->setStatus('rewind', null);
        } catch (\Throwable $e) {
            $this->screen->setStatus('rewind', 'File rewind failed: '.$e->getMessage());
        }
        $this->tui->requestRender();
    }
}
