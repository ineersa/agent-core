<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveBackgroundChildPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Tick listener that polls for new runtime events.
 *
 * Delegates polling logic to RuntimeEventPoller and updates the
 * transcript display and working status when new events arrive.
 *
 * Also wires runtime human_input.requested events into the TUI
 * QuestionCoordinator/QuestionController so that HITL/interrupt
 * questions show interactive overlays and answers are dispatched
 * back to the runtime via answer_human commands.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * The service itself is stateless; per-run state comes from the context.
 */
final class TickPollListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SubagentLivePickerController $subagentLivePickerController,
        private readonly RuntimeEventPoller $poller,
        private readonly SubagentLiveChildViewPoller $subagentLiveChildPoller,
        private readonly SubagentLiveBackgroundChildPoller $subagentLiveBackgroundChildPoller,
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
        private readonly RuntimeQuestionEventHandler $runtimeQuestionEventHandler,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $poller = $this->poller;
        $state = $context->state;
        $client = $context->client;
        $screen = $context->screen;
        $questionCoordinator = $this->questionCoordinator;
        $questionController = $this->questionController;
        $subagentLiveChildPoller = $this->subagentLiveChildPoller;
        $subagentLiveBackgroundChildPoller = $this->subagentLiveBackgroundChildPoller;
        $runtimeQuestionEventHandler = $this->runtimeQuestionEventHandler;
        $subagentLivePickerController = $this->subagentLivePickerController;

        // Wire the question controller with TUI runtime references
        $questionController->setRuntimeRefs($context, $screen);

        $context->ticks->add(static function () use ($poller, $state, $client, $screen, $questionCoordinator, $questionController, $subagentLiveChildPoller, $subagentLiveBackgroundChildPoller, $runtimeQuestionEventHandler, $subagentLivePickerController): ?bool {
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

            if (!$liveActive) {
                $subagentLiveBackgroundChildPoller->poll(
                    $state,
                    $client,
                    $screen,
                    onHumanInputRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolQuestionRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolTerminal: static function (RuntimeEvent $event) use ($questionCoordinator, $questionController, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
                    },
                );
            }

            // Child-first on the shared JSONL pipe: events() re-buffers non-matching
            // run ids; polling the child run before the parent reduces child latency.
            if ($liveActive) {
                $subagentLiveBackgroundChildPoller->pollCatalogIngest($state, $client);

                $ingestNestedCatalog = static function (RuntimeEvent $event) use ($state): void {
                    $state->subagentLiveCatalog->ingestNestedProgressFromChildRunEvent($event);
                };

                $childBlocks = $subagentLiveChildPoller->poll(
                    $state->subagentLiveView,
                    $client,
                    onHumanInputRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleHumanInputRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolQuestionRequested: static function (RuntimeEvent $event) use ($client, $questionCoordinator, $state, $screen, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolQuestionRequested($event, $client, $questionCoordinator, $state, $screen);
                    },
                    onToolTerminal: static function (RuntimeEvent $event) use ($questionCoordinator, $questionController, $runtimeQuestionEventHandler): void {
                        $runtimeQuestionEventHandler->handleToolTerminal($event, $questionCoordinator, $questionController);
                    },
                    onCatalogIngest: $ingestNestedCatalog,
                );
                if (null !== $childBlocks) {
                    $screen->setTranscriptBlocks($childBlocks);
                }
            }

            if ($liveActive) {
                $poller->pollStateOnly(
                    $state,
                    $client,
                    onHumanInputRequested: $onHitl,
                    onToolQuestionRequested: $onToolQuestion,
                    onToolTerminal: $onToolTerminal,
                );
                $changedBlocks = null;
            } else {
                $changedBlocks = $poller->poll(
                    $state,
                    $client,
                    onHumanInputRequested: $onHitl,
                    onToolQuestionRequested: $onToolQuestion,
                    onToolTerminal: $onToolTerminal,
                );
            }

            if ($liveActive) {
                $selected = $state->subagentLiveView->selected;
                if (null !== $selected) {
                    $refreshed = $state->subagentLiveCatalog->findByArtifactId($selected->artifactId);
                    if (null !== $refreshed) {
                        $state->subagentLiveView->selected = $refreshed;
                        if (SubagentLiveStatusEnum::WaitingHuman === $refreshed->status) {
                            $state->subagentLiveView->childActivity = RunActivityStateEnum::WaitingHuman;
                        } elseif ($refreshed->isRunning()) {
                            $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;
                        } elseif ($refreshed->isTerminal()) {
                            $state->subagentLiveView->childActivity = match ($refreshed->status) {
                                SubagentLiveStatusEnum::Completed, SubagentLiveStatusEnum::Done => RunActivityStateEnum::Completed,
                                SubagentLiveStatusEnum::Failed => RunActivityStateEnum::Failed,
                                SubagentLiveStatusEnum::Cancelled => RunActivityStateEnum::Cancelled,
                                default => RunActivityStateEnum::Completed,
                            };
                        }
                    }
                }
            } elseif (null !== $changedBlocks) {
                $screen->setTranscriptBlocks($state->transcript);
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
                if (null !== $activeRequest) {
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
