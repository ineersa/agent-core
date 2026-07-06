<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
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
 * Picker for /agents-live subagent live view — select a child subagent run for interactive steering.
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
            $this->screen?->setWorkingMessage('No known subagents yet. Launch a subagent first.');

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
            text: $theme->muted('Agents live — Enter live view, d removes, Ctrl+\\ main, Esc cancel'),
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

        $picker = $this;

        $listWidget->onSelectionChange(
            static function (SelectionChangeEvent $event) use ($listWidget, &$children, $theme): void {
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

        $listWidget->onInput(static function (string $data) use ($picker, $listWidget, &$children, $theme, $screen, $state): bool {
            if ('d' !== $data && 'D' !== $data) {
                return false;
            }

            $picker->dismissSelected($listWidget, $children, $theme, $screen, $state);

            return true;
        });

        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    /**
     * @param list<SubagentLiveChildDTO> $children
     */
    private function dismissSelected(
        SelectListWidget $listWidget,
        array &$children,
        TuiTheme $theme,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $selected = $listWidget->getSelectedItem();
        if (null === $selected) {
            return;
        }

        $artifactId = (string) ($selected['value'] ?? '');
        $removed = $state->subagentLiveCatalog->dismissArtifactId($artifactId);
        if (null === $removed) {
            return;
        }

        $state->subagentLiveView->removeChildCache($removed->agentRunId);

        if ($state->subagentLiveView->active
            && null !== $state->subagentLiveView->selected
            && $state->subagentLiveView->selected->artifactId === $artifactId) {
            SubagentLiveMainReturn::returnToMain($state, $screen, requestRender: false);
        }

        $children = array_values(array_filter(
            $children,
            static fn (SubagentLiveChildDTO $child): bool => $child->artifactId !== $artifactId,
        ));

        if ([] === $children) {
            $this->closePicker();
            $screen->setWorkingMessage('No known subagents yet. Launch a subagent first.');
            $screen->requestRender(true);

            return;
        }

        $idx = 0;
        $selectedValue = (string) ($selected['value'] ?? '');
        foreach ($children as $i => $child) {
            if ($child->artifactId === $selectedValue) {
                $idx = $i;
                break;
            }
        }
        $idx = min($idx, \count($children) - 1);
        $listWidget->setItems(self::buildItems($children, $theme, selectedIndex: $idx));
        $listWidget->setSelectedIndex($idx);

        $msg = $removed->isRunning()
            ? \sprintf('Removed %s from /agents-live (child continues in background).', $removed->agentName)
            : \sprintf('Removed %s from /agents-live.', $removed->agentName);
        $screen->setWorkingMessage($msg);
        $screen->requestRender(true);
    }

    private function enterLiveView(SubagentLiveChildDTO $child, TuiSessionState $state, ChatScreen $screen): void
    {
        $cached = $state->subagentLiveView->childCaches[$child->agentRunId] ?? null;
        $hasCachedTranscript = null !== $cached && [] !== $cached['transcript'];

        $resetProjection = !$hasCachedTranscript && $state->subagentLiveView->shouldResetProjectionFor($child);
        if ($resetProjection) {
            $this->childPoller->resetProjection();
        }

        $state->subagentLiveView->enter($child);

        if ($resetProjection && [] === $state->subagentLiveView->childTranscript) {
            $state->subagentLiveView->childTranscript = $state->subagentLiveView->placeholderTranscriptFor($child);
            $state->subagentLiveView->persistCurrentChildCache();
        }

        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);
        $screen->syncQueuedUserMessages($state->subagentLiveView->childQueuedUserMessages);
        $screen->setWorkingMessage($child->isRunning() ? 'Child agent working...' : 'Child agent idle');
        $screen->requestRender(true);
    }
}
