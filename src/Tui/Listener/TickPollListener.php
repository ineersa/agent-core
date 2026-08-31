<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Tick listener that polls for new runtime events.
 *
 * Delegates polling logic to the per-session RuntimeEventPoller and
 * updates the transcript display and working status when new events arrive.
 *
 * Also wires runtime human_input.requested events into the per-session
 * QuestionCoordinator/QuestionController so that HITL/interrupt
 * questions show interactive overlays and answers are dispatched
 * back to the runtime via answer_human commands.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * The service itself is stateless; per-run state comes from the context's
 * session service scope.
 */
final class TickPollListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly RuntimeQuestionEventHandler $runtimeQuestionEventHandler,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $services = $context->sessionServices;
        $poller = $services->parentPoller;
        $state = $context->state;
        $client = $context->client;
        $screen = $context->screen;
        $questionCoordinator = $services->questionCoordinator;
        $questionController = $services->questionController;
        $subagentLiveChildPoller = $services->childPoller;
        $runtimeQuestionEventHandler = $this->runtimeQuestionEventHandler;
        $subagentLivePickerController = $services->subagentLivePicker;

        $context->ticks->add(static function () use ($poller, $state, $client, $screen, $questionCoordinator, $questionController, $subagentLiveChildPoller, $runtimeQuestionEventHandler, $subagentLivePickerController): ?bool {
            $onHitl = static function (RuntimeEvent $event) use ($client, $questionCoordinator, $runtimeQuestionEventHandler): void {
                $runtimeQuestionEventHandler->handleHumanInputRequested($event, $client, $questionCoordinator);
            };

            $onToolQuestion = static function (RuntimeEvent $event) use ($client, $questionCoordinator, $runtimeQuestionEventHandler): void {
                $runtimeQuestionEventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator);
            };

            $onToolTerminal = static function (RuntimeEvent $event) use ($questionCoordinator, $questionController, $runtimeQuestionEventHandler): void {
                $runtimeQuestionEventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
            };

            $liveActive = $state->subagentLiveView->active;

            // Child-first on the shared JSONL pipe: events() re-buffers non-matching
            // run ids; polling the child run before the parent reduces child latency.
            if ($liveActive) {
                $childBlocks = $subagentLiveChildPoller->poll(
                    $state->subagentLiveView,
                    $client,
                    onHumanInputRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolQuestionRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator, $state);
                    },
                    onToolTerminal: static function (RuntimeEvent $event) use ($questionCoordinator, $questionController, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
                    },
                );
                // Only repaint transcript when new child blocks arrive; cached blocks stay on screen.
                if (null !== $childBlocks) {
                    $screen->setTranscriptBlocks($childBlocks);
                }

                // Reconcile selected child's terminal runtime activity into the catalog
                // before parent subagent_progress can overwrite with a stale nonterminal row.
                $selectedForReconcile = $state->subagentLiveView->selected;
                if (null !== $selectedForReconcile && $state->subagentLiveView->childActivity->isTerminal()) {
                    $terminalStatus = match ($state->subagentLiveView->childActivity) {
                        RunActivityStateEnum::Completed => SubagentLiveStatusEnum::Completed,
                        RunActivityStateEnum::Failed => SubagentLiveStatusEnum::Failed,
                        RunActivityStateEnum::Cancelled => SubagentLiveStatusEnum::Cancelled,
                        default => null,
                    };
                    if (null !== $terminalStatus) {
                        $state->subagentLiveCatalog->applyChildStatus($selectedForReconcile->artifactId, $terminalStatus);
                    }
                }
            }

            $transcriptChanges = $poller->poll(
                $state,
                $client,
                onHumanInputRequested: $onHitl,
                onToolQuestionRequested: $onToolQuestion,
                onToolTerminal: $onToolTerminal,
            );

            if ($liveActive) {
                $selected = $state->subagentLiveView->selected;
                if (null !== $selected) {
                    $refreshed = $state->subagentLiveCatalog->findByArtifactId($selected->artifactId);
                    if (null !== $refreshed) {
                        $state->subagentLiveView->selected = $refreshed;
                        // Do not let stale nonterminal catalog overwrite terminal/cancelling runtime.
                        $preserveLocalActivity = $state->subagentLiveView->childActivity->isTerminal()
                            || RunActivityStateEnum::Cancelling === $state->subagentLiveView->childActivity;
                        if (!$preserveLocalActivity) {
                            $mappedActivity = $refreshed->status->toActivity();
                            if (null !== $mappedActivity) {
                                $state->subagentLiveView->childActivity = $mappedActivity;
                            }
                        } elseif ($refreshed->isTerminal() && RunActivityStateEnum::Cancelling !== $state->subagentLiveView->childActivity) {
                            $state->subagentLiveView->childActivity = $refreshed->status->toActivity() ?? $state->subagentLiveView->childActivity;
                        }
                    }
                }
            } elseif (null !== $transcriptChanges) {
                // Incremental projector delta (or explicit full after history-position replace).
                // State already applied inside RuntimeEventPoller; screen merges the same set.
                $screen->applyTranscriptChangeSet($transcriptChanges);
            }

            // /history selection: populate editor with the selected user prompt once.
            if (null !== $state->pendingEditorPromptText) {
                $screen->promptEditor()->replaceText($state->pendingEditorPromptText);
                $state->pendingEditorPromptText = null;
            }

            // The pending-queue widget (slot 4, above the editor) reflects transient
            // queued steer/follow-up messages. Sync every tick regardless of transcript
            // changes, since a user.message_queued event mutates state without a block.
            if ($liveActive) {
                $screen->syncQueuedUserMessages($state->subagentLiveView->childQueuedUserMessages);
            } else {
                $screen->syncQueuedUserMessages($state->queuedUserMessages);
            }

            // Open the question overlay whenever the coordinator has an
            // active request and the controller is not already showing it
            // AND is not awaiting free-form editor input (__other__ escape
            // hatch). This handles: (a) new questions becoming active after
            // polling uncovers a human_input.requested event, and (b) queued
            // questions advancing into the active slot on later ticks. The
            // isAwaitingFreeForm() check prevents rebuilding the select
            // overlay while the user types a custom answer in the editor.
            if ($questionCoordinator->actionRequired() && !$questionController->isOpen() && !$questionController->isAwaitingFreeForm()) {
                $activeRequest = $questionCoordinator->activeRequest();
                $visibleOwnerRunId = $state->visibleQuestionOwnerRunId();
                // Only surface questions owned by the currently visible run (parent main / selected child).
                if (null !== $activeRequest
                    && (null === $activeRequest->runId || $activeRequest->runId === $visibleOwnerRunId)) {
                    $questionController->open($activeRequest);
                }
            }

            // Self-heal: if the run left the active states (cancelled/terminal via ESC
            // or error) while a HITL question is still pending, the question is
            // orphaned. reject() advances the queue WITHOUT invoking callbacks (safe
            // for a dead run — sends nothing to the runtime) and close() clears
            // awaitingFreeForm so a subsequently-queued HITL question can activate.
            // Without this, ESC during __other__ free-form typing cancels the run but
            // leaves awaitingFreeForm=true, silently suppressing the next question.
            if ($questionCoordinator->actionRequired()) {
                $activeRequest = $questionCoordinator->activeRequest();
                if (null !== $activeRequest && $runtimeQuestionEventHandler->shouldRejectOrphanedQuestion($state, $activeRequest)) {
                    $questionCoordinator->reject();
                    $questionController->close();
                }
            }

            $mainViewPendingQuestion = !$liveActive
                && $questionCoordinator->actionRequired()
                && !$questionController->isAwaitingFreeForm();

            if ($mainViewPendingQuestion) {
                $screen->setWorkingVisible(false);
            } else {
                $screen->setWorkingVisible(true);
            }

            // Update working status based on authoritative activity state.
            // SubmitListener sets 'Working...' optimistically on send;
            // this keeps it visible while active and clears it when idle/terminal.
            //
            // Cancelling gets its own message ('Cancelling...') because
            // CancelListener sets it once on Escape, and this tick renderer
            // would otherwise overwrite it back to 'Working...' on the very
            // next tick. Rendering the correct message from the activity state
            // rather than a binary idle/active toggle keeps the footer truthful
            // even when the activity state is sticky Cancelling through late deltas.
            //
            // Always call setWorkingMessage — don't use a static last-value
            // cache. SubmitListener (and future features like shell commands)
            // may call setWorkingMessage directly between tick cycles, and a
            // stale static cache would skip the authoritative tick update,
            // permanently leaving a stuck working message.
            if ($liveActive) {
                $parentMsg = match (true) {
                    RunActivityStateEnum::Cancelling === $state->activity => 'Cancelling...',
                    RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal() => null,
                    null === $state->handle && $state->activity->isActive() => null,
                    default => 'Working...',
                };
                $childMsg = match ($state->subagentLiveView->childActivity) {
                    RunActivityStateEnum::WaitingHuman => 'Child waiting for your input...',
                    RunActivityStateEnum::Cancelling => 'Child cancelling...',
                    default => $state->subagentLiveView->childActivity->isActive()
                        ? 'Child agent working...'
                        : 'Child agent idle',
                };
                $liveWorking = null !== $parentMsg
                    ? $parentMsg.' | '.$childMsg
                    : $childMsg;
                // Live-view-only cache: generic tick path avoids static last-value (see comment above).
                if ($liveWorking !== $state->subagentLiveView->lastLiveWorkingMessage) {
                    $state->subagentLiveView->lastLiveWorkingMessage = $liveWorking;
                    $screen->setWorkingMessage($liveWorking);
                }

                SubagentLiveAttention::refreshAttentionFooter($state, $screen);

                return self::shouldKeepActiveRuntimeTicks($state, true) ? true : null;
            }

            if ($subagentLivePickerController->isOpen()) {
                $subagentLivePickerController->refreshIfOpen();

                SubagentLiveAttention::syncMainAttention($state, $screen);

                return self::shouldKeepActiveRuntimeTicks($state, false) ? true : null;
            }

            if ($mainViewPendingQuestion) {
                $screen->setWorkingMessage(null);
                SubagentLiveAttention::syncMainAttention($state, $screen);

                return self::shouldKeepActiveRuntimeTicks($state, false) ? true : null;
            }

            $msg = match (true) {
                RunActivityStateEnum::Cancelling === $state->activity => 'Cancelling...',
                RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal() => null,
                // Resumed sessions replay activity but have no live handle until
                // start_run/follow_up attaches the controller — do not show Working.
                null === $state->handle && $state->activity->isActive() => null,
                default => 'Working...',
            };

            SubagentLiveAttention::syncMainAttention($state, $screen);

            $screen->setWorkingMessage($msg);

            return self::shouldKeepActiveRuntimeTicks($state, false) ? true : null;
        });
    }

    /**
     * Hint Symfony TUI to tick at active cadence (~10ms) while runtime work is in flight.
     *
     * RuntimeEventPoller/SubagentLiveChildViewPoller still cap their own poll work at 50ms;
     * this only affects how often the TUI event loop invokes tick handlers so stdout JSONL
     * can be drained promptly during streaming. Idle/terminal states return null so the
     * adaptive ticker falls back to the slow idle rate (CPU fix from prior work).
     */
    private static function shouldKeepActiveRuntimeTicks(TuiSessionState $state, bool $liveActive): bool
    {
        if ($liveActive) {
            if ($state->subagentLiveView->childActivity->isActive()) {
                return true;
            }

            return $state->activity->isActive() && null !== $state->handle;
        }

        return $state->activity->isActive() && null !== $state->handle;
    }
}
