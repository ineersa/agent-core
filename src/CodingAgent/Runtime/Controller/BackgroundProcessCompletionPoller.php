<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Polls for completed background processes that should notify the user.
 *
 * When a bash command is explicitly moved to background (user accepted
 * the prompt), the process continues running in its own session. Once
 * it finishes, this poller:
 * 1. Detects the completed process (backgroundedAt set, finishedAt now set)
 * 2. Reads the log tail (capped at 3000 chars, matching pi bg-process.ts)
 * 3. Builds a synthetic follow-up message with PID, exit code, command,
 *    and output tail
 * 4. Sends an append_message UserCommand so the model sees the completion
 *    as a user-level message (like pi's sendUserMessage with deliverAs: followUp)
 * 5. Marks completionNotifiedAt to prevent re-notification
 *
 * Only processes explicitly backgrounded by the user through the
 * background prompt trigger this notification — foreground commands
 * that completed normally are handled by BashTool's own loop and
 * never reach this poller.
 *
 * This mirrors pi's bg-process.ts behavior where finalizeBackgroundProcess()
 * calls pi.sendUserMessage(text, { deliverAs: 'followUp' }) on child close.
 */
final class BackgroundProcessCompletionPoller
{
    private const float POLL_INTERVAL = 2.0;

    /**
     * Maximum log tail chars to include in notification (matching pi's 3000).
     */
    private const int NOTIFICATION_TAIL_CHARS = 3000;

    /**
     * @param string|null $sessionId parent controller session/run ID from
     *                               HATFIELD_SESSION_ID. When null, poll is a no-op.
     */
    private readonly ?string $sessionId;

    public function __construct(
        private readonly ProcessStore $processStore,
        private readonly BackgroundProcessManager $processManager,
        private readonly AgentSessionClient $sessionClient,
        private readonly RuntimeEventEmitter $emitter,
        private readonly LoggerInterface $logger,
        private readonly AgentArtifactRegistry $artifactRegistry,
        ?string $sessionId = null,
    ) {
        // Normalize empty string to null so repository null-guards
        // (null !== $sessionId) work correctly when the env var is
        // undefined or set to empty string in non-controller contexts.
        $this->sessionId = (null !== $sessionId && '' !== $sessionId)
            ? $sessionId
            : null;
    }

    /**
     * Register the background process completion poll loop.
     */
    public function startPollLoop(float $interval = self::POLL_INTERVAL): void
    {
        EventLoop::repeat($interval, function (): void {
            if ($this->emitter->isShuttingDown()) {
                return;
            }

            $this->poll();
        });
    }

    private function poll(): void
    {
        $sessionIds = $this->resolveOwnedSessionIds();
        if ([] === $sessionIds) {
            $this->logger->debug('bg_process_completion.poll_skipped_no_session', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.poll_skipped_no_session',
            ]);

            return;
        }

        $pendingByPid = [];

        foreach ($sessionIds as $sessionId) {
            // A backgrounded process that finishes naturally only writes its
            // status file; the DB entity still has finishedAt=NULL. Without
            // this refresh, findPendingNotifications() would never select it.
            try {
                $this->processManager->refreshAllUnfinished($sessionId);
            } catch (\Throwable $e) {
                $this->logger->warning('bg_process_completion.refresh_failed', [
                    'component' => 'bg_process_completion.poller',
                    'event_type' => 'bg_process_completion.refresh_failed',
                    'controller_session_id' => $this->sessionId,
                    'scoped_session_id' => $sessionId,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            try {
                foreach ($this->processStore->findPendingNotifications($sessionId) as $process) {
                    $pendingByPid[$process->pid] = $process;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('bg_process_completion.poller_query_failed', [
                    'component' => 'bg_process_completion.poller',
                    'event_type' => 'bg_process_completion.poller_query_failed',
                    'controller_session_id' => $this->sessionId,
                    'scoped_session_id' => $sessionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        foreach ($pendingByPid as $process) {
            try {
                $this->sendNotification($process);
            } catch (\Throwable $e) {
                $this->logger->warning('bg_process_completion.poller_send_failed', [
                    'component' => 'bg_process_completion.poller',
                    'event_type' => 'bg_process_completion.poller_send_failed',
                    'process_pid' => $process->pid,
                    'run_id' => $process->sessionId,
                    'exception' => $e->getMessage(),
                ]);
                // Do NOT mark notified — retry on next poll.
            }
        }
    }

    /**
     * Session IDs this controller owns: parent session plus child agent run IDs.
     *
     * @return list<string>
     */
    private function resolveOwnedSessionIds(): array
    {
        if (null === $this->sessionId) {
            return [];
        }

        $ids = [$this->sessionId];

        try {
            foreach ($this->artifactRegistry->list($this->sessionId) as $entry) {
                $childRunId = $entry->agentRunId;
                if ('' !== $childRunId && !\in_array($childRunId, $ids, true)) {
                    $ids[] = $childRunId;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('bg_process_completion.artifact_list_failed', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.artifact_list_failed',
                'controller_session_id' => $this->sessionId,
                'exception' => $e->getMessage(),
            ]);
        }

        return $ids;
    }

    /**
     * Build and send a follow-up notification for a completed background process.
     */
    private function sendNotification(\Ineersa\CodingAgent\Entity\BackgroundProcess $process): void
    {
        $pid = $process->pid;
        $sessionId = $process->sessionId;

        if ('' === $sessionId) {
            // Unscopped process — cannot send follow-up without a run.
            // Mark notified to avoid re-polling.
            $now = new \DateTimeImmutable();
            $process->markCompletionNotified($now);
            $this->processStore->flush();

            $this->logger->info('bg_process_completion.skipped_no_session', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.skipped_no_session',
                'process_pid' => $pid,
            ]);

            return;
        }

        // Read log tail, matching pi's 3000 char cap.
        $output = '';
        try {
            $tailResult = $this->processManager->readLogTailForRecord($process->id, self::NOTIFICATION_TAIL_CHARS, $sessionId);
            $output = $tailResult->content;
        } catch (\Throwable $e) {
            $output = \sprintf('[Could not read log: %s]', $e->getMessage());

            $this->logger->warning('bg_process_completion.log_read_failed', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.log_read_failed',
                'process_pid' => $pid,
                'run_id' => $sessionId,
            ]);
        }

        // Build notification message matching pi's bg-process.ts format.
        $exitCode = $process->exitCode;
        $statusLabel = match ($process->status) {
            \Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum::Stopped => 'stopped',
            \Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum::FinishedUnclean => 'unclean exit',
            default => null !== $exitCode ? (string) $exitCode : '?',
        };

        $commandPreview = \strlen($process->command) > 200
            ? substr($process->command, 0, 197).'...'
            : $process->command;

        $notification = \sprintf(
            "[BG_PROCESS_DONE] PID %d finished (exit %s)\nCommand: %s\n\nOutput (last %d chars):\n%s",
            $pid,
            $statusLabel,
            $commandPreview,
            self::NOTIFICATION_TAIL_CHARS,
            $output,
        );

        // Emit a runtime event for the TUI to show if desired.
        $this->emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::BackgroundProcessCompleted->value,
            runId: $sessionId,
            seq: 0,
            payload: [
                'pid' => $pid,
                'exit_code' => $exitCode,
                'status' => $process->status->value,
                'command_preview' => $commandPreview,
                'output_tail' => $output,
            ],
        ));

        // Send append_message so the model sees the completion (same text as TUI transcript).
        $this->sessionClient->send($sessionId, new UserCommand(
            type: 'append_message',
            text: $notification,
        ));

        // Only mark notified after successful send.
        $now = new \DateTimeImmutable();
        $process->markCompletionNotified($now);
        $this->processStore->flush();

        $this->logger->info('bg_process_completion.notification_sent', [
            'component' => 'bg_process_completion.poller',
            'event_type' => 'bg_process_completion.notification_sent',
            'process_pid' => $pid,
            'run_id' => $sessionId,
            'exit_code' => $exitCode,
            'status' => $process->status->value,
        ]);
    }
}
