<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotDTO;
use Ineersa\CodingAgent\Runtime\Contract\ChildRunTranscriptSnapshotProviderInterface;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Listener\FooterStateInitializer;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
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
    private ?TextWidget $headerWidget = null;
    private ?Tui $tui = null;
    private ?ChatScreen $screen = null;
    private ?TuiSessionState $state = null;

    private ?AgentSessionClient $client = null;

    /** @var ?callable(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private $onHumanInputRequested;

    /** @var ?callable(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private $onToolQuestionRequested;

    /** @var ?callable(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private $onToolTerminal;

    public function __construct(
        private readonly SubagentLiveChildViewPoller $childPoller,
        private readonly ChildRunTranscriptSnapshotProviderInterface $childSnapshotProvider,
        private readonly ChildAgentEventsPathResolverInterface $childEventsPathResolver,
        private readonly SessionEventsExportService $exportService,
    ) {
    }

    public function setRuntimeRefs(
        Tui $tui,
        ChatScreen $screen,
        TuiSessionState $state,
        ?AgentSessionClient $client = null,
        ?callable $onHumanInputRequested = null,
        ?callable $onToolQuestionRequested = null,
        ?callable $onToolTerminal = null,
    ): void {
        $this->tui = $tui;
        $this->screen = $screen;
        $this->state = $state;
        $this->client = $client;
        $this->onHumanInputRequested = $onHumanInputRequested;
        $this->onToolQuestionRequested = $onToolQuestionRequested;
        $this->onToolTerminal = $onToolTerminal;
    }

    public function open(): void
    {
        if ($this->isOpen()) {
            return;
        }

        $children = $this->state?->subagentLiveCatalog->all() ?? [];
        if ([] === $children) {
            $screen = $this->screen;
            if (null !== $screen) {
                $screen->setWorkingMessage(null);
                $screen->setStatus('agents-live', null);
                $screen->requestRender(true);
            }

            return;
        }

        $this->openWithChildren($children);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    public function refreshPickerFeedbackIfOpen(): void
    {
        $state = $this->state;
        $screen = $this->screen;
        if (null === $state || null === $screen || !$this->isOpen()) {
            return;
        }

        $feedback = $state->subagentLiveView->pickerFeedbackMessage;
        if (null === $feedback || '' === trim($feedback)) {
            return;
        }

        if ($feedback === $state->subagentLiveView->lastPickerFeedbackWorkingMessage) {
            return;
        }

        $this->applyPickerFeedbackToUi($feedback, requestRender: true);
    }

    public function closePicker(bool $requestRender = true): void
    {
        if (null !== $this->state) {
            $this->state->subagentLiveView->pickerFeedbackMessage = null;
            $this->state->subagentLiveView->lastPickerFeedbackWorkingMessage = null;
        }
        $this->overlay?->close($requestRender);
        $this->overlay = null;
        $this->headerWidget = null;
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
            $ctxFormatted = ContextUsageFormatter::format($child->model, $child->latestInputTokens, $child->contextWindow);
            if (null !== $ctxFormatted) {
                $suffix = $ctxFormatted->text;
                if (null !== $child->model && '' !== $child->model) {
                    $suffix .= ' '.FooterStateInitializer::shortModelName($child->model);
                }
                $label .= ' · '.$suffix;
            }
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
        if ($this->isOpen()) {
            return;
        }

        $this->closePicker(requestRender: false);

        $tui = $this->tui;
        $screen = $this->screen;
        $state = $this->state;
        if (null === $tui || null === $screen || null === $state) {
            return;
        }

        $theme = $screen->theme();
        $header = new TextWidget(
            text: $this->buildPickerHeaderText($theme),
            // Full export paths must stay inspectable in the picker header (may wrap on narrow terminals).
            truncate: false,
        );
        $this->headerWidget = $header;

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

        // Arrow navigation uses SelectListWidget native highlight only.
        // Rebuilding items via setItems() on every SelectionChangeEvent leaves stale
        // overlay rows (incremental render) and was reported as growing duplicate rows.

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
            if ('e' === $data || 'E' === $data) {
                $picker->exportSelected($listWidget, $screen, $state);

                return true;
            }

            if ('d' !== $data && 'D' !== $data) {
                return false;
            }

            $picker->dismissSelected($listWidget, $children, $theme, $screen, $state);

            return true;
        });

        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    private function buildPickerHeaderText(TuiTheme $theme): string
    {
        $base = 'Agents live — Enter live view, e export, d dismisses finished, Ctrl+\ main, Esc cancel';
        $feedback = $this->state?->subagentLiveView->pickerFeedbackMessage;
        if (null === $feedback || '' === trim($feedback)) {
            return $theme->muted($base);
        }

        return $theme->muted($base.' | '.$feedback);
    }

    private function showPickerFeedback(string $message): void
    {
        $state = $this->state;
        if (null === $state) {
            return;
        }

        $state->subagentLiveView->pickerFeedbackMessage = $message;
        $state->subagentLiveView->lastPickerFeedbackWorkingMessage = null;
        $this->applyPickerFeedbackToUi($message, requestRender: true);
    }

    private function applyPickerFeedbackToUi(string $message, bool $requestRender): void
    {
        $state = $this->state;
        $screen = $this->screen;
        if (null === $state || null === $screen || !$this->isOpen()) {
            return;
        }

        $state->subagentLiveView->lastPickerFeedbackWorkingMessage = $message;
        $screen->setWorkingMessage($message);

        $header = $this->headerWidget;
        if (null !== $header) {
            $header->setText($this->buildPickerHeaderText($screen->theme()));
        }

        if ($requestRender) {
            $screen->requestRender(true);
        }
    }

    private function exportSelected(
        SelectListWidget $listWidget,
        ChatScreen $screen,
        TuiSessionState $state,
    ): void {
        $selected = $listWidget->getSelectedItem();
        if (null === $selected) {
            $this->showPickerFeedback('No child agent selected to export.');

            return;
        }

        $artifactId = (string) ($selected['value'] ?? '');
        $child = $state->subagentLiveCatalog->findByArtifactId($artifactId);
        if (null === $child) {
            $this->showPickerFeedback('Selected child agent is no longer in the catalog.');

            return;
        }

        $parentSessionId = $state->sessionId;
        if ('' === $parentSessionId) {
            $this->showPickerFeedback('No active parent session — cannot export child run.');

            return;
        }

        try {
            $eventsPath = $this->childEventsPathResolver->eventsPath($parentSessionId, $artifactId);
        } catch (\InvalidArgumentException $e) {
            $this->showPickerFeedback($e->getMessage());

            return;
        }

        $outputPath = getcwd().'/hatfield-child-'.$artifactId.'.html';
        $title = \sprintf('Child %s (%s)', $child->agentName, $artifactId);

        try {
            $message = $this->exportService->exportEventsFile(
                $eventsPath,
                $outputPath,
                $child->agentRunId,
                $title,
                '',
                '',
            );
            if (str_starts_with($message, 'Session exported to: ')) {
                $message = 'Child agent exported to: '.substr($message, \strlen('Session exported to: '));
            }
            $this->showPickerFeedback($message);
        } catch (\RuntimeException $e) {
            $this->showPickerFeedback($e->getMessage());
        }
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
        $child = $state->subagentLiveCatalog->findByArtifactId($artifactId);
        if (null === $child) {
            return;
        }

        if ($child->isRunning()) {
            $this->showPickerFeedback(\sprintf(
                'Cannot remove active subagent %s; wait for completion or cancel it first.',
                $child->agentName,
            ));

            return;
        }

        $removed = $state->subagentLiveCatalog->dismissArtifactId($artifactId);
        if (null === $removed) {
            return;
        }

        $state->subagentLiveView->removeChildCache($removed->agentRunId);

        if ($state->subagentLiveView->active
            && null !== $state->subagentLiveView->selected
            && $state->subagentLiveView->selected->artifactId === $artifactId) {
            SubagentLiveMainReturn::returnToMain($state, $screen, $this->client, requestRender: false);
        }

        $children = array_values(array_filter(
            $children,
            static fn (SubagentLiveChildDTO $child): bool => $child->artifactId !== $artifactId,
        ));

        if ([] === $children) {
            $this->closePicker();
            $screen->setWorkingMessage(null);
            $screen->setStatus('agents-live', null);
            $screen->requestRender(true);

            return;
        }

        $idx = 0;
        $selectedValue = (string) ($selected['value'] ?? '');
        foreach ($children as $i => $remainingChild) {
            if ($remainingChild->artifactId === $selectedValue) {
                $idx = $i;
                break;
            }
        }
        $idx = min($idx, \count($children) - 1);
        $listWidget->setItems(self::buildItems($children, $theme, selectedIndex: $idx));
        $listWidget->setSelectedIndex($idx);

        $this->showPickerFeedback(\sprintf('Removed %s from /agents-live.', $removed->agentName));
    }

    private function enterLiveView(SubagentLiveChildDTO $child, TuiSessionState $state, ChatScreen $screen): void
    {
        $client = $this->client;
        if (null !== $client) {
            $previous = $state->subagentLiveView->selected;
            if (null !== $previous && $previous->agentRunId !== $child->agentRunId) {
                $client->endObservingChildRun($previous->agentRunId);
            }

            $client->beginObservingChildRun($child->agentRunId);
        }

        $cached = $state->subagentLiveView->childCaches[$child->agentRunId] ?? null;
        $hasCachedTranscript = null !== $cached && [] !== $cached['transcript'];

        $state->subagentLiveView->enter($child);

        if ($hasCachedTranscript) {
            $cachedReplay = $state->subagentLiveView->childReplayEvents;
            $this->childPoller->replaySnapshot(
                $state->subagentLiveView,
                new ChildRunTranscriptSnapshotDTO(
                    $state->subagentLiveView->childTranscript,
                    $cachedReplay,
                    $state->subagentLiveView->childLastSeq,
                ),
            );
        } else {
            $this->childPoller->resetProjection();

            $snapshot = $this->childSnapshotProvider->snapshot($child->agentRunId);
            if ([] === $snapshot->transcriptBlocks && [] === $snapshot->replayEvents) {
                $state->subagentLiveView->childTranscript = $state->subagentLiveView->placeholderTranscriptFor($child);
                $state->subagentLiveView->persistCurrentChildCache();
            } else {
                $this->childPoller->replaySnapshot(
                    $state->subagentLiveView,
                    $snapshot,
                    onHumanInputRequested: $this->onHumanInputRequested,
                    onToolQuestionRequested: $this->onToolQuestionRequested,
                    onToolTerminal: $this->onToolTerminal,
                );
            }
        }

        $screen->setTranscriptBlocks($state->subagentLiveView->childTranscript);
        $screen->syncQueuedUserMessages($state->subagentLiveView->childQueuedUserMessages);
        $screen->setWorkingMessage($child->isRunning() ? 'Child agent working...' : 'Child agent idle');
        $screen->requestRender(true);
    }
}
