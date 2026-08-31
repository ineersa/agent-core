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
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\SelectListKeybindings;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SelectEvent;
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

    /**
     * Invoked with the previous child run id when leaving/switching away from it.
     * Listener layer wires question cleanup here (Deptrac: picker must not import TuiQuestion).
     *
     * @var ?\Closure(string): void
     */
    private readonly ?\Closure $onLeavingChildRun;

    /** @var ?\Closure(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private readonly ?\Closure $onHumanInputRequested;

    /** @var ?\Closure(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private readonly ?\Closure $onToolQuestionRequested;

    /** @var ?\Closure(\Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent): void */
    private readonly ?\Closure $onToolTerminal;

    public function __construct(
        private readonly Tui $tui,
        private readonly ChatScreen $screen,
        private readonly TuiSessionState $state,
        private readonly AgentSessionClient $client,
        private readonly SubagentLiveChildViewPoller $childPoller,
        private readonly ChildRunTranscriptSnapshotProviderInterface $childSnapshotProvider,
        private readonly ChildAgentEventsPathResolverInterface $childEventsPathResolver,
        private readonly SessionEventsExportService $exportService,
        ?\Closure $onHumanInputRequested = null,
        ?\Closure $onToolQuestionRequested = null,
        ?\Closure $onToolTerminal = null,
        ?\Closure $onLeavingChildRun = null,
    ) {
        $this->onHumanInputRequested = $onHumanInputRequested;
        $this->onToolQuestionRequested = $onToolQuestionRequested;
        $this->onToolTerminal = $onToolTerminal;
        $this->onLeavingChildRun = $onLeavingChildRun;
    }

    public function open(): void
    {
        if ($this->isOpen()) {
            return;
        }

        $children = $this->state->subagentLiveCatalog->all();
        if ([] === $children) {
            $screen = $this->screen;
            $screen->setWorkingMessage(null);
            $screen->setStatus('agents-live', null);
            $screen->requestRender(true);

            return;
        }

        $this->openWithChildren($children);
    }

    public function isOpen(): bool
    {
        return $this->overlay?->isOpen() ?? false;
    }

    public function refreshIfOpen(): void
    {
        if (!$this->isOpen()) {
            return;
        }

        $state = $this->state;
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
        $this->state->subagentLiveView->pickerFeedbackMessage = null;
        $this->state->subagentLiveView->lastPickerFeedbackWorkingMessage = null;
        $this->overlay?->close($requestRender);
        $this->overlay = null;
        $this->headerWidget = null;
    }

    /**
     * Plain labels only — {@see SelectListWidget} owns selected-row marker/style.
     *
     * @param list<SubagentLiveChildDTO> $children
     *
     * @return list<array{value: string, label: string}>
     */
    public static function buildItems(array $children): array
    {
        $items = [];
        foreach ($children as $child) {
            $task = PickerListLabelFormatter::sanitizeTitle($child->taskSummary);
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

        $theme = $screen->theme();
        $header = new TextWidget(
            text: $this->buildPickerHeaderText($theme),
            // Full export paths must stay inspectable in the picker header (may wrap on narrow terminals).
            truncate: false,
        );
        $this->headerWidget = $header;

        $kb = SelectListKeybindings::standard();

        $items = self::buildItems($children);
        $listWidget = new SelectListWidget(
            items: $items,
            maxVisible: SelectListKeybindings::MAX_VISIBLE,
            keybindings: $kb,
        );

        $picker = $this;

        // Arrow navigation uses SelectListWidget native highlight only.
        // Do not bake Accent into labels or rebuild items on SelectionChangeEvent.

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

        $listWidget->onInput(static function (string $data) use ($picker, $listWidget, $screen, $state): bool {
            if ('e' === $data || 'E' === $data) {
                $picker->exportSelected($listWidget, $screen, $state);

                return true;
            }

            if ('d' !== $data && 'D' !== $data) {
                return false;
            }

            $picker->dismissSelected($listWidget, $screen, $state);

            return true;
        });

        $this->overlay = new PickerOverlay();
        $this->overlay->mount($tui, $screen, $listWidget, $header);
    }

    private function buildPickerHeaderText(TuiTheme $theme): string
    {
        $base = 'Agents live — Enter live view, e export, d dismisses finished, Ctrl+\ main, Esc cancel';
        $feedback = $this->state->subagentLiveView->pickerFeedbackMessage;
        if (null === $feedback || '' === trim($feedback)) {
            return $theme->muted($base);
        }

        return $theme->muted($base.' | '.$feedback);
    }

    private function showPickerFeedback(string $message): void
    {
        $state = $this->state;
        $state->subagentLiveView->pickerFeedbackMessage = $message;
        $state->subagentLiveView->lastPickerFeedbackWorkingMessage = null;
        $this->applyPickerFeedbackToUi($message, requestRender: true);
    }

    private function applyPickerFeedbackToUi(string $message, bool $requestRender): void
    {
        $state = $this->state;
        $screen = $this->screen;
        if (!$this->isOpen()) {
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

    private function dismissSelected(
        SelectListWidget $listWidget,
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
            $leavingRunId = $state->subagentLiveView->selected->agentRunId;
            if (null !== $this->onLeavingChildRun) {
                ($this->onLeavingChildRun)($leavingRunId);
            }
            SubagentLiveMainReturn::returnToMain($state, $screen, $this->client, requestRender: false);
        }

        $children = $state->subagentLiveCatalog->all();
        if ([] === $children) {
            $this->closePicker();
            $screen->setWorkingMessage(null);
            $screen->setStatus('agents-live', null);
            $screen->requestRender(true);

            return;
        }

        // Selected artifact was just removed; previous dead search always resolved to 0.
        $listWidget->setItems(self::buildItems($children));
        $listWidget->setSelectedIndex(0);

        $this->showPickerFeedback(\sprintf('Removed %s from /agents-live.', $removed->agentName));
    }

    private function enterLiveView(SubagentLiveChildDTO $child, TuiSessionState $state, ChatScreen $screen): void
    {
        $client = $this->client;
        $previous = $state->subagentLiveView->selected;
        if (null !== $previous && $previous->agentRunId !== $child->agentRunId) {
            // Drop previous child's active/queued HITL before switching visible owner.
            if (null !== $this->onLeavingChildRun) {
                ($this->onLeavingChildRun)($previous->agentRunId);
            }
            $client->endObservingChildRun($previous->agentRunId);
        }

        $client->beginObservingChildRun($child->agentRunId);

        $cached = $state->subagentLiveView->childCaches[$child->agentRunId] ?? null;
        $hasCachedTranscript = null !== $cached && [] !== $cached['transcript'];

        $state->subagentLiveView->enter($child);

        if ($hasCachedTranscript) {
            // Re-entry must re-dispatch HITL/tool callbacks: leave/switch silently
            // removed coordinator questions, and cached childLastSeq would skip the
            // original waiting event on subsequent poll(). Request-ID dedupe in
            // RuntimeQuestionEventHandler is the safety net if a question remains.
            $cachedReplay = $state->subagentLiveView->childReplayEvents;
            $this->childPoller->replaySnapshot(
                $state->subagentLiveView,
                new ChildRunTranscriptSnapshotDTO(
                    $state->subagentLiveView->childTranscript,
                    $cachedReplay,
                    $state->subagentLiveView->childLastSeq,
                ),
                onHumanInputRequested: $this->onHumanInputRequested,
                onToolQuestionRequested: $this->onToolQuestionRequested,
                onToolTerminal: $this->onToolTerminal,
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
        // Child reasoning colours the editor frame while live; main footerReasoning is left alone.
        $screen->applyEditorBorderColor($child->reasoning ?? '');
        $screen->requestRender(true);
    }
}
