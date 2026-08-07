<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveAttention;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\CancelEvent;

/**
 * Handles Escape key — cancels the active run or clears the editor.
 *
 * When a run is active (Starting/Running/WaitingHuman/Cancelling),
 * sends a cancel command to the runtime via AgentSessionClient,
 * transitions activity to Cancelling, and shows a status message.
 *
 * When idle or in a terminal state, clears the editor text.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 *
 * Exception handling: cancel failures delegate capture vs rethrow to
 * RuntimeExceptionBoundary. The boundary owns the HATFIELD_CAPTURE_ERRORS
 * policy — CancelListener never checks it directly.
 */
final class CancelListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly QuestionController $questionController,
        private readonly QuestionCoordinator $questionCoordinator,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $logger = $this->logger;
        $boundary = $this->boundary;
        $questionController = $this->questionController;
        $questionCoordinator = $this->questionCoordinator;

        $context->tui->addListener(static function (CancelEvent $event) use ($client, $state, $screen, $logger, $boundary, $questionController, $questionCoordinator): void {
            // Free-form typing (__other__ escape hatch): ESC returns to the
            // select list instead of cancelling the run. The user can then ESC
            // again from the list to cancel the question (→ 'Cancelled by user').
            if ($questionController->isAwaitingFreeForm()) {
                $questionController->restoreFromFreeForm();

                return;
            }

            if ($questionCoordinator->actionRequired()) {
                $active = $questionCoordinator->activeRequest();
                $visibleOwnerRunId = $state->visibleQuestionOwnerRunId();
                $activeOwnedByVisible = null !== $active
                    && (null === $active->runId || $active->runId === $visibleOwnerRunId);

                // Only answer/cancel questions owned by the currently visible run.
                // Stale child questions left in the coordinator must not cancel from main.
                if ($activeOwnedByVisible) {
                    if (QuestionKind::Text === $active->kind) {
                        $questionCoordinator->cancel();
                        $questionController->close();
                        if (null !== $active->runId && '' !== $active->runId) {
                            SubagentLiveAttention::clearWaitingHumanForRun($state, $screen, $active->runId);
                        }

                        return;
                    }

                    // Choice/confirm/approval overlays own Esc while open.
                    if ($questionController->isOpen()) {
                        return;
                    }

                    // Child-owned non-text HITL without an open overlay: cancel the question only.
                    $parentRunId = null !== $state->handle ? $state->handle->runId : $state->sessionId;
                    if ($active->runId !== $parentRunId) {
                        $questionCoordinator->cancel();
                        $questionController->close();
                        if (null !== $active->runId && '' !== $active->runId) {
                            SubagentLiveAttention::clearWaitingHumanForRun($state, $screen, $active->runId);
                        }

                        return;
                    }

                    // Parent HITL with no open overlay — do not cascade into run cancel.
                    return;
                }
            }

            $live = $state->subagentLiveView;
            // Live child view owns Esc entirely: never fall through to parent cancel.
            if ($live->active && null !== $live->selected) {
                $child = $live->selected;
                if (!self::shouldCancelSelectedChild($child, $live->childActivity)) {
                    // Terminal/idle selected child: consume Esc locally (no parent cascade).
                    return;
                }

                $logger->info('ESC cancel child subagent requested', [
                    'component' => 'cancel_listener',
                    'event_type' => 'subagent_live_child_cancel_requested',
                    'run_id' => $child->agentRunId,
                    'artifact_id' => $child->artifactId,
                    'agent_name' => $child->agentName,
                ]);

                try {
                    $client->cancel($child->agentRunId);
                } catch (\Throwable $e) {
                    $boundary->catch($e, 'cancel_listener.child_cancel_command_failed', [
                        'run_id' => $child->agentRunId,
                    ]);

                    $logger->error('Child cancel command failed', [
                        'component' => 'cancel_listener',
                        'event_type' => 'subagent_live_child_cancel_failed',
                        'run_id' => $child->agentRunId,
                        'artifact_id' => $child->artifactId,
                        'exception' => $e,
                    ]);
                    $screen->setWorkingMessage('Child cancel failed: '.$e->getMessage());

                    return;
                }

                // Request is nonterminal until runtime/artifact confirms cancellation.
                $live->childActivity = RunActivityStateEnum::Cancelling;
                $screen->setWorkingMessage(\sprintf(
                    'Cancelling child %s (%s)…',
                    $child->agentName,
                    $child->artifactId,
                ));

                return;
            }

            // Active run (Starting/Running/WaitingHuman/Cancelling) — send cancel.
            // Compacting: auto-compaction maintenance is in flight — also
            // send cancel so the user can abort a stuck compaction (session 13).
            //
            // Compacting.isActive() returns false (SubmitListener routes
            // user input as follow_up, not steer, to avoid racing compaction).
            // CancelListener must still send cancel during Compacting because
            // CancelListener's own guard checks isActive() — we special-case
            // Compacting here rather than making it report as isActive.
            //
            // Failed + lastRuntimePollError: RuntimeEventPoller set activity Failed
            // after any fatal transport/poll error while a run handle may still be
            // present (broken pipe, controller crash, restart limit, etc.) with no
            // writable controller stdin. Escape must attempt cancel and surface
            // recovery text, not clearEditor. Ordinary runtime Failed events without
            // lastRuntimePollError remain terminal and still clear the editor.
            $transportFailedWithHandle = RunActivityStateEnum::Failed === $state->activity
                && null !== $state->handle
                && '' !== $state->lastRuntimePollError;

            if (($state->activity->isActive() || RunActivityStateEnum::Compacting === $state->activity || $transportFailedWithHandle)
                && null !== $state->handle) {
                $logger->info('ESC cancel requested', [
                    'run_id' => $state->handle->runId,
                    'activity' => $state->activity->value,
                ]);

                try {
                    $client->cancel($state->handle->runId);
                } catch (\Throwable $e) {
                    // Delegate capture=0 rethrow vs capture=1 recovery to boundary.
                    // If we reach here, capture mode is enabled — show TUI error.
                    $boundary->catch($e, 'cancel_listener.cancel_command_failed', [
                        'run_id' => $state->handle->runId,
                    ]);

                    $logger->error('Cancel command failed', [
                        'run_id' => $state->handle->runId,
                        'exception' => $e,
                    ]);
                    $state->activity = RunActivityStateEnum::Failed;
                    $block = new TranscriptBlock(
                        id: \sprintf('cancel_error_%s', $state->handle->runId),
                        kind: TranscriptBlockKindEnum::Error,
                        runId: $state->handle->runId,
                        seq: $state->lastSeq + 1,
                        text: 'Cancel failed: '.$e->getMessage()
                            .' The runtime process may have crashed. Please restart the agent.',
                        meta: ['exception' => $e::class],
                    );
                    $state->appendTranscriptBlock($block);

                    return;
                }

                SubagentLiveAttention::markActiveChildrenCancelledForParentCancel($state, $screen);
                $state->activity = RunActivityStateEnum::Cancelling;
                $screen->setWorkingMessage('Cancelling...');

                return;
            }

            // Idle/terminal — just clear the editor
            $screen->clearEditor();
        });
    }

    private static function shouldCancelSelectedChild(
        SubagentLiveChildDTO $child,
        RunActivityStateEnum $childActivity,
    ): bool {
        return $child->isRunning()
            || RunActivityStateEnum::Cancelling === $childActivity
            || $childActivity->isActive();
    }
}
