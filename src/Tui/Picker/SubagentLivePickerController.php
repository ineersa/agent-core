<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
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
 * Picker for /agents-live readonly subagent live view — select a child subagent run for readonly live view.
 */
final class SubagentLivePickerController
{
    private ?PickerOverlay $overlay = null;
    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    public function __construct(
        private readonly SubagentLiveChildViewPoller $childPoller,
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
        $children = $this->state?->subagentLiveCatalog->all() ?? [];
        if ([] === $children) {
            $this->screen?->setStatus('agents-live', 'No known subagents yet. Launch a subagent first.');

            return;
        }

        $this->openWithChildren($children);
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

    /**
     * @param list<SubagentLiveChildDTO> $children
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(array $children, TuiTheme $theme, int $selectedIndex = -1): array
    {
        $items = [];
        foreach ($children as $i => $child) {
            $task = $child->taskSummary;
            if (\strlen($task) > 48) {
                $task = substr($task, 0, 45).'...';
            }
            $statusLabel = $child->needsAttention() ? '⚠ needs input' : $child->statusLabel();
            $runShort = \strlen($child->agentRunId) > 12 ? substr($child->agentRunId, 0, 12).'…' : $child->agentRunId;
            $label = \sprintf('%s [%s] %s run:%s — %s', $child->agentName, $statusLabel, $child->artifactId, $runShort, $task);
            if ($i === $selectedIndex) {
                $label = $theme->color(ThemeColorEnum::Accent, $label);
            }
            $items[] = [
                'value' => $child->artifactId,
                'label' => $label,
            ];
        }

        return $items;
    }

    /**
     * @param list<SubagentLiveChildDTO> $children
     */
    private function openWithChildren(array $children): void
    {
        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;
        if (null === $tui || null === $screen || null === $state) {
            return;
        }

        $theme = $screen->theme();
        $header = new TextWidget(
            text: $theme->muted('Agents live — arrows move, Enter opens readonly view, Esc cancels'),
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

        $items = self::buildItems($children, $theme, selectedIndex: 0);
        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: 10,
            keybindings: $kb,
        );

        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, $children, $theme): void {
                $selectedValue = $event->getItem()['value'];
                $selectedIdx = -1;

                foreach ($children as $i => $child) {
                    if ($child->artifactId === $selectedValue) {
                        $selectedIdx = $i;

                        break;
                    }
                }

                $newItems = self::buildItems($children, $theme, selectedIndex: $selectedIdx);
                $listWidget->setItems($newItems);
                $listWidget->setSelectedIndex(max(0, $selectedIdx));
            },
        );

        $picker = $this;
        $listWidget->onSelect(static function (SelectEvent $event) use ($picker, $screen, $state): void {
            $item = $event->getItem();
            $artifactId = (string) ($item['value'] ?? '');
            $child = $state->subagentLiveCatalog->findByArtifactId($artifactId);
            if (null === $child) {
                $picker->closePicker();

                return;
            }

            $picker->enterLiveView($child, $state, $screen);
            $picker->closePicker();
        });

        $listWidget->onCancel(static function (CancelEvent $event) use ($picker): void {
            $picker->closePicker();
        });

        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    private function enterLiveView(SubagentLiveChildDTO $child, TuiSessionState $state, ChatScreen $screen): void
    {
        $resetProjection = $state->subagentLiveView->shouldResetProjectionFor($child);
        if ($resetProjection) {
            $this->childPoller->resetProjection();
        }

        $state->subagentLiveView->enter($child);

        if ($resetProjection && [] === $state->subagentLiveView->childTranscript) {
            $state->subagentLiveView->childTranscript = $state->subagentLiveView->placeholderTranscriptFor($child);
        }

        $screen->setStatus('agents-live', \sprintf(
            'Live view (readonly): %s [%s] run %s — /agents-main to return.',
            $child->agentName,
            $child->statusLabel(),
            $child->agentRunId,
        ));
        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);
        $screen->setWorkingMessage($child->isRunning() ? 'Child agent working...' : 'Child agent idle');
        $screen->requestRender(true);
    }
}
