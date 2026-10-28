<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Contract\Tool\MalformedToolResultException;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Tool\BackgroundProcess\DTO\BashProcessRecordDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\String\u;

/**
 * Polls for completed background processes that should notify the user.
 *
 * When a bash command is explicitly moved to background (user requested detachment), the process continues running in its own session. Once
 * it finishes, this poller:
 * 1. Detects the completed process (backgroundedAt set, finishedAt now set)
 * 2. Reads the log tail (capped at 3000 chars, matching pi bg-process.ts)
 * 3. Builds a synthetic follow-up message with PID, exit code, command,
 *    and output tail
 * 4. Submits completion to run_control for authoritative admission against
 *    current stop state and the retained delivery receipt
 * 5. Leaves completionNotifiedAt to the owner, which acknowledges only after
 *    durable mailbox or forwarding admission
 *
 * Only processes explicitly backgrounded by the user through the
 * background shortcut trigger this notification — foreground commands
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
    private bool $polling = false;

    public function __construct(
        private readonly ProcessStore $processStore,
        private readonly BackgroundProcessManager $processManager,
        private readonly MessageBusInterface $commandBus,
        private readonly RuntimeEventEmitter $emitter,
        private readonly LoggerInterface $logger,
        private readonly AgentArtifactRegistry $artifactRegistry,
        private readonly BackgroundCompletionReceiptRepository $receipts,
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

    /**
     * Session IDs owned by a controller: parent session plus child agent run IDs.
     *
     * The caller-provided parent ID is authoritative so controller lifecycle
     * listeners cannot accidentally act on this poller's configured scope.
     *
     * @return list<string>
     */
    public function resolveOwnedSessionIds(string $parentSessionId): array
    {
        $ids = [$parentSessionId];

        try {
            foreach ($this->artifactRegistry->list($parentSessionId) as $entry) {
                $childRunId = $entry->agentRunId;
                if ('' !== $childRunId && !\in_array($childRunId, $ids, true)) {
                    $ids[] = $childRunId;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('bg_process_completion.artifact_list_failed', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.artifact_list_failed',
                'exception_class' => $e::class,
            ]);
        }

        return $ids;
    }

    private function poll(): void
    {
        // A broker reply can suspend past another tick. Keep selection, send
        // and the notified mark within one pass, not merely one transport send.
        if ($this->polling) {
            return;
        }
        $this->polling = true;
        try {
            $this->pollCompletions();
        } finally {
            $this->polling = false;
        }
    }

    private function pollCompletions(): void
    {
        if (null === $this->sessionId) {
            $this->logger->debug('bg_process_completion.poll_skipped_no_session', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.poll_skipped_no_session',
            ]);

            return;
        }

        $sessionIds = $this->resolveOwnedSessionIds($this->sessionId);
        $pendingByPid = [];

        try {
            // Terminal child mailboxes can need retirement without an AdvanceRun.
            // Forwarding then remains outstanding until root admission commits.
            foreach ($this->receipts->recoverableDeliveries($this->sessionId) as $receipt) {
                $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO(
                    $receipt->destinationRunId, $receipt->identity,
                    $receipt->destinationRunId === $receipt->sourceRunId ? $receipt->text : $receipt->forwardedText,
                    $receipt->sourceRunId,
                    $receipt->deferredId,
                    $receipt->invocation,
                ));
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('background_completion.forwarding_retry_failed', [
                'run_id' => $this->sessionId,
                'session_id' => $this->sessionId,
                'component' => 'bg_process_completion.poller',
                'event_type' => 'background_completion.forwarding_retry_failed',
                'exception_class' => $exception::class,
            ]);
        }

        $failed = [];
        try {
            // Deadline/grace supervision still owns native controller actions.
            // Its batch persistence is migrated separately from notification
            // admission; never substitute a passive observation for a timeout.
            $unfinished = $this->processStore->fetchAllUnfinished($sessionIds);
            usort($unfinished, static fn (BackgroundProcess $left, BackgroundProcess $right): int => $left->id <=> $right->id);
            $this->processManager->refreshEntities($unfinished, function (BackgroundProcess $process, \Throwable $exception) use (&$failed): void {
                $failed[$process->id] = true;
                $this->logger->warning('bg_process_completion.refresh_failed', [
                    'component' => 'bg_process_completion.poller',
                    'event_type' => 'bg_process_completion.refresh_failed',
                    'run_id' => $process->sessionId,
                    'session_id' => $this->sessionId,
                    'exception_class' => $exception::class,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->logger->warning('bg_process_completion.refresh_flush_failed', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.refresh_flush_failed',
                'run_id' => $this->sessionId,
                'session_id' => $this->sessionId,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        try {
            $candidates = array_values(array_filter($this->processStore->completionSnapshots($sessionIds), static fn (BashProcessRecordDTO $record): bool => !isset($failed[$record->id])));
        } catch (\Throwable $e) {
            $this->logger->warning('bg_process_completion.poller_query_failed', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.poller_query_failed',
                'controller_session_id' => $this->sessionId,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // Preserve the former scope order and duplicate-PID precedence. The
        // stable sort retains the query's record-ID order within each scope.
        $scopeOrder = array_flip($sessionIds);
        usort($candidates, static fn (BashProcessRecordDTO $left, BashProcessRecordDTO $right): int => $scopeOrder[$left->sessionId] <=> $scopeOrder[$right->sessionId]);

        // Observe each immutable record through its metadata owner. The
        // resulting snapshot is still only a candidate for owner admission.
        foreach ($candidates as $candidate) {
            $process = $this->processManager->findByRecordId($candidate->id, $candidate->sessionId);
            if (null === $process) {
                continue;
            }
            if (null !== $process->finishedAt && null !== $process->backgroundedAt && null === $process->completionNotifiedAt) {
                $pendingByPid[$process->pid] = $process;
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
     * Build and send a follow-up notification for a completed background process.
     */
    private function sendNotification(BashProcessRecordDTO $process): void
    {
        $pid = $process->pid;
        $sessionId = $process->sessionId;

        // Read log tail, matching pi's 3000 char cap.
        $output = '';
        try {
            $tailResult = $this->processManager->readLogTailForRecord($process->id, self::NOTIFICATION_TAIL_CHARS, $sessionId);
            $output = $tailResult->content;
        } catch (MalformedToolResultException $e) {
            // Deliver the failure, not altered output or an endless read retry.
            $output = ToolResultText::FAILURE_MESSAGE;
            $this->logger->warning('bg_process_completion.malformed_output', [
                'component' => 'bg_process_completion.poller',
                'event_type' => 'bg_process_completion.malformed_output',
                'process_pid' => $pid,
                'run_id' => $sessionId,
            ]);
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

        $commandPreview = u($process->command)->truncate(200, '...')->toString();

        $notification = \sprintf(
            "[BG_PROCESS_DONE] PID %d finished (exit %s)\nCommand: %s\n\nOutput (last %d chars):\n%s",
            $pid,
            $statusLabel,
            $commandPreview,
            self::NOTIFICATION_TAIL_CHARS,
            $output,
        );

        // Recipient selection happens under the consuming owner's lock. A child
        // that finishes before delivery must not be revived by its Bash result.
        $this->commandBus->dispatch(new DeliverBackgroundCompletionDTO(
            $sessionId, 'background-process-'.$process->id, $notification, processRecordId: $process->id, outputTail: $output,
        ));

        // The owner marks only after retained mailbox/forwarding admission.
        // A successful transport send alone is not a completion receipt.
        $this->logger->info('bg_process_completion.notification_submitted', [
            'component' => 'bg_process_completion.poller',
            'event_type' => 'bg_process_completion.notification_submitted',
            'process_pid' => $pid,
            'run_id' => $sessionId,
            'exit_code' => $exitCode,
            'status' => $process->status->value,
        ]);
    }
}
