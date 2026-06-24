<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
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
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $logger = $this->logger;
        $boundary = $this->boundary;

        $context->tui->addListener(static function (CancelEvent $event) use ($client, $state, $screen, $logger, $boundary): void {
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
                    $state->transcript[] = $block;

                    return;
                }

                $state->activity = RunActivityStateEnum::Cancelling;
                $screen->setWorkingMessage('Cancelling...');

                return;
            }

            // Idle/terminal — just clear the editor
            $screen->clearEditor();
        });
    }
}
