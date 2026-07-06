<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiExtensionContextInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class FileRewindPickerController
{
    private ?PickerOverlay $overlay = null;
    private ?TuiExtensionContextInterface $tui = null;
    private ?string $sessionId = null;
    private ?TextWidget $headerWidget = null;

    public function __construct(
        private readonly FileRewindService $service,
    ) {
    }

    public function wire(TuiExtensionContextInterface $context): void
    {
        $this->tui = $context;
    }

    public function open(): void
    {
        $tui = $this->tui;
        if (null === $tui) {
            return;
        }
        $sessionId = $tui->getSessionId();
        if ('' === $sessionId) {
            $tui->setStatus('rewind', 'File rewind requires an active session.');
            $tui->requestRender();

            return;
        }
        $this->sessionId = $sessionId;
        if ($this->overlay?->isOpen() ?? false) {
            return;
        }
        if (null === $this->tui) {
            return;
        }
        if (!$this->service->isEnabled()) {
            $this->tui->setStatus('rewind', 'File rewind is disabled.');
            $this->tui->requestRender();

            return;
        }
        if (!$this->service->isOperational()) {
            $this->tui->setStatus('rewind', 'File rewind is unavailable (git missing).');
            $this->tui->requestRender();

            return;
        }
        $targets = $this->restorableTargets($sessionId);
        if ([] === $targets) {
            $this->tui->setStatus('rewind', 'No file rewind checkpoints are available yet.');
            $this->tui->requestRender();

            return;
        }
        $this->openTurnPicker($targets);
    }

    public function closePicker(bool $requestRender = true): void
    {
        if (null !== $this->tui) {
            $this->overlay?->close($this->tui, $requestRender);
        }
        $this->overlay = null;
        $this->headerWidget = null;
        $this->tui?->setStatus('rewind', null);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    /**
     * @param list<array{turnNo:int,title:string,displayRole:string}> $targets
     */
    private function openTurnPicker(array $targets): void
    {
        $tui = $this->tui;
        if (null === $tui) {
            return;
        }
        $this->headerWidget = new TextWidget(text: $tui->formatMuted('File rewind — select checkpoint (Esc to close)'), truncate: true);
        $kb = new Keybindings([
            'select_up' => [Key::UP],
            'select_down' => [Key::DOWN],
            'select_confirm' => [Key::ENTER],
            'select_cancel' => [Key::ESCAPE, Key::ctrl('c')],
        ]);
        $selected = max(0, \count($targets) - 1);
        $items = $this->buildCheckpointItems($targets);
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
        $this->overlay->mount($tui, $list, $this->headerWidget);
        $initial = $targets[$selected];
        $this->updateHeaderForTurn($initial['turnNo'], $initial['title']);
    }

    private function updateHeaderForTurn(int $turnNo, string $title): void
    {
        if (null === $this->headerWidget || null === $this->tui) {
            return;
        }
        $label = $this->sanitizeTitle($title);
        $this->headerWidget->setText($this->tui->formatMuted('Checkpoint turn '.$turnNo.': '.$label.' (Esc to close)'));
        $this->tui->setStatus('rewind', null);
        $this->tui->requestRender();
    }

    /**
     * @param list<array{turnNo:int,title:string,displayRole:string}> $targets
     *
     * @return list<array{value:string,label:string}>
     */
    private function buildCheckpointItems(array $targets): array
    {
        $items = [];
        $tui = $this->tui;
        if (null === $tui) {
            return [];
        }
        foreach ($targets as $target) {
            $turnNo = $target['turnNo'];
            $body = $this->sanitizeTitle($target['title']);
            $role = '' !== $target['displayRole'] ? $target['displayRole'] : 'assistant';
            $prefix = $tui->formatRolePrefix($role);
            $label = $prefix.' checkpoint '.$turnNo.': '.$body;
            $items[] = ['value' => (string) $turnNo, 'label' => $label];
        }

        return $items;
    }

    /**
     * @return list<array{turnNo:int,title:string,displayRole:string}>
     */
    private function restorableTargets(string $sessionId): array
    {
        $tui = $this->tui;
        if (null === $tui) {
            return [];
        }
        $targets = [];
        foreach ($tui->turnRowsInDisplayOrder($sessionId) as $row) {
            $turnNo = $row['turnNo'];
            if (!$this->service->hasCheckpointForTurn($sessionId, $turnNo)) {
                continue;
            }
            $title = $row['title'];
            if (preg_match('/^Turn \d+$/', $title)) {
                $title = 'Checkpoint (turn '.$turnNo.')';
            }
            $targets[] = ['turnNo' => $turnNo, 'title' => $title, 'displayRole' => $row['displayRole']];
        }

        return $targets;
    }

    private function sanitizeTitle(string $title): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $title);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ('' === $text) {
            return '';
        }
        $text = preg_replace('/^>\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^[-*]\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^#+\s+/u', '', $text) ?? $text;

        return trim($text);
    }

    private function restoreCheckpoint(string $sessionId, int $turnNo): void
    {
        if (null === $this->tui) {
            return;
        }
        if (!$this->service->hasCheckpointForTurn($sessionId, $turnNo)) {
            $this->tui->setStatus('rewind', 'Selected checkpoint is no longer available.');
            $this->tui->requestRender();

            return;
        }
        try {
            $this->service->restoreForTurn($sessionId, $turnNo);
            $this->tui->setStatus('rewind', null);
        } catch (\Throwable $e) {
            $this->tui->setStatus('rewind', 'File rewind failed: '.$e->getMessage());
        }
        $this->tui->requestRender();
    }
}
