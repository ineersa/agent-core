<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Replay\SessionColdReconstructionService;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Initializes session state for the interactive TUI.
 *
 * Handles:
 *   - New session creation (generates ID, no file persistence)
 *   - Session resumption (replays transcript from canonical events.jsonl)
 *   - Initial run start (if a pre-configured request is provided)
 *
 * Extracted from InteractiveMode::run() so the session lifecycle
 * is independently testable and the run() method stays lean.
 *
 * On resume, transcript blocks are rebuilt from the retained-history prefix
 * at the explicit int position (0 = before first / empty) via HistoryProvider
 * + SessionTranscriptProvider. Fail-closed: projection failure propagates;
 * only unreadable events.jsonl degrades locally to a system block.
 */
final readonly class SessionInitializer
{
    public function __construct(
        private HatfieldSessionStore $sessionStore,
        private TranscriptBlockFactory $blockFactory,
        private LoggerInterface $logger,
        private SessionColdReconstructionService $coldReconstruction,
    ) {
    }

    /**
     * Initialize a fresh draft session state without creating a DB row.
     *
     * The session is created lazily by SubmitListener when the user
     * submits their first message — no orphan DB/session records are
     * created if the user types /new and never sends a message.
     *
     * @param StartRunRequest|null $request Optional pre-configured request
     */
    public function initializeDraft(?StartRunRequest $request = null): TuiSessionState
    {
        $state = new TuiSessionState(sessionId: '', resuming: false);

        if (null !== $request) {
            $state->request = $request;
        }

        return $state;
    }

    /**
     * Initialize session state and return ready-to-use TuiSessionState.
     *
     * @param string               $sessionId Existing session ID to resume; empty = new session
     * @param StartRunRequest|null $request   Optional pre-configured start request
     */
    public function initialize(
        string $sessionId = '',
        ?StartRunRequest $request = null,
    ): TuiSessionState {
        $resuming = '' !== $sessionId && $this->sessionStore->exists($sessionId);

        if (!$resuming) {
            $promptText = null !== $request ? $request->prompt : '';
            $sessionId = $this->sessionStore->createSession($promptText);
        }

        $state = new TuiSessionState(sessionId: $sessionId, resuming: $resuming);

        // Attachments survive reload and history truncation; the retained transcript
        // cannot tell us which image numbers are still occupied on disk.
        $attachmentsDir = $this->sessionStore->resolveSessionsBasePath().'/'.$sessionId.'/attachments';
        if ($resuming && is_dir($attachmentsDir)) {
            foreach (Finder::create()->files()->depth(0)->in($attachmentsDir)->name('/^pasted-image-\d+\.[^.]+$/') as $file) {
                if (preg_match('/^pasted-image-(\d+)\./', $file->getFilename(), $match)) {
                    $state->nextPastedImageIndex = max($state->nextPastedImageIndex, (int) $match[1] + 1);
                }
            }
        }

        // Inject session ID as the run ID when starting with an initial prompt
        if (null !== $request && '' !== $request->prompt && '' === $request->runId) {
            $state->request = new StartRunRequest(
                prompt: $request->prompt,
                runId: $sessionId,
                cwd: $request->cwd,
                options: $request->options,
                model: $request->model,
                reasoning: $request->reasoning,
            );
        } elseif (null !== $request) {
            $state->request = $request;
        }

        return $state;
    }

    /**
     * Build the initial transcript blocks for the session.
     *
     * On resume, rebuilds the retained-history transcript at the current
     * position using the caller-provided fresh parent event applier (whose
     * projector is scoped to the current session). On fresh session, returns
     * a welcome block.
     *
     * Returns plain projection blocks; theme colors/prefixes are applied
     * at display time by ChatScreen/TranscriptMountedWidget.
     *
     * @param TuiRuntimeEventApplier $eventApplier session-scoped parent applier
     *
     * @return list<TranscriptBlock>
     */
    public function buildInitialTranscript(TuiSessionState $state, TuiRuntimeEventApplier $eventApplier): array
    {
        if ($state->resuming) {
            return $this->replayFromEvents($state, $eventApplier);
        }

        // Fresh session or draft: no file persistence — events.jsonl is the canonical record.
        // A welcome block is returned for in-memory display only.
        // For draft sessions (sessionId === ''), use a placeholder runId.
        $runId = '' !== $state->sessionId ? $state->sessionId : '(new draft)';

        return [$this->blockFactory->system(
            runId: $runId,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        )];
    }

    /**
     * Rebuild retained-history transcript for resume (never full-stream fallback).
     *
     * @param TuiRuntimeEventApplier $eventApplier session-scoped parent applier
     *
     * @return list<TranscriptBlock>
     */
    private function replayFromEvents(TuiSessionState $state, TuiRuntimeEventApplier $eventApplier): array
    {
        // Use the session ID as the run ID (they are the same in Hatfield).
        $runId = $state->sessionId;

        try {
            $result = $this->coldReconstruction->reconstruct(
                runId: $runId,
                publishSharedState: true,
                publishHistory: true,
            );
        } catch (\Throwable $e) {
            // Intentional local degradation: events.jsonl is unreadable.
            // Log the error with sanitised correlation fields so operators
            // can diagnose without seeing raw prompts or tool output.
            $this->logger->warning('Session transcript replay: events.jsonl unreadable, falling back to system block', [
                'component' => 'SessionInitializer',
                'event_type' => 'replay_events_unreadable',
                'session_id' => $runId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — could not load events.',
                seq: 1,
            )];
        }

        if (0 === $result->lastSeq) {
            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — no messages yet.',
                seq: 1,
            )];
        }

        $historyAwareBlocks = $result->transcript->transcriptBlocks;

        $this->applyResumeProjection($state, $eventApplier, $result->transcript->resume, $historyAwareBlocks);

        // Set lastSeq so the live poller does not re-process replayed events.
        // Always derived from the full canonical stream max, never regressed.
        $state->lastSeq = $result->lastSeq;

        if ($state->isShellRun = $result->isShellOnlySession) {
            // Restored for SubmitListener: next normal prompt must start() not follow_up.
        }

        // Passive resume: historical mid-turn activity must not imply a live run.
        // Attach does not continue AgentCore; stale Running/Cancelling/Compacting
        // would route new input as steer and show Working without a runtime process.
        // Compacting is not isActive() but still sets isCompacting and activity.
        if ($state->activity->isActive() || RunActivityStateEnum::Compacting === $state->activity) {
            $state->activity = RunActivityStateEnum::Idle;
            $state->isCompacting = false;
        }

        // When the canonical stream already ended (agent_end) or failed, align
        // replayed activity with the terminal outcome even if retained-history
        // replay stopped before the final agent_end (history position / discard filter).
        $terminalActivity = $this->terminalActivityFromReason($result->terminalActivityReason);
        if (null !== $terminalActivity
            && !$result->suppressTerminalActivityForInProgressCompaction) {
            $state->activity = $terminalActivity;
            $state->isCompacting = false;
        }

        if ([] !== $historyAwareBlocks) {
            return $historyAwareBlocks;
        }

        return [$this->blockFactory->system(
            runId: $runId,
            text: 'Session '.$runId.' — no messages yet.',
            seq: 1,
        )];
    }

    /**
     * @param list<TranscriptBlock> $transcriptBlocks
     */
    private function applyResumeProjection(
        TuiSessionState $state,
        TuiRuntimeEventApplier $eventApplier,
        \Ineersa\CodingAgent\Runtime\Contract\SessionResumeProjectionDTO $resume,
        array $transcriptBlocks,
    ): void {
        $state->usage->inputTokens = $resume->usageInputTokens;
        $state->usage->outputTokens = $resume->usageOutputTokens;
        $state->usage->totalCost = $resume->usageTotalCost;
        $state->usage->latestInputTokens = $resume->usageLatestInputTokens;
        $state->usage->cacheReadTokens = $resume->usageCacheReadTokens;
        $state->usage->cacheCreationTokens = $resume->usageCacheCreationTokens;
        $state->usage->hasCacheTelemetry = $resume->usageHasCacheTelemetry;
        $state->usage->resetTurnForReplay();
        $state->queuedUserMessages = $resume->queuedUserMessages;
        $state->llmRetryWorkingMessage = $resume->llmRetryWorkingMessage;
        $state->activity = RunActivityStateEnum::tryFrom($resume->activity) ?? RunActivityStateEnum::Idle;
        $state->isCompacting = $resume->isCompacting;

        foreach ($resume->subagentProgressSnapshots as $snapshot) {
            $state->subagentLiveCatalog->ingestSnapshot($snapshot);
        }

        $eventApplier->hydrateProjectedTranscript($transcriptBlocks);
    }

    private function terminalActivityFromReason(?string $reason): ?RunActivityStateEnum
    {
        if (null === $reason) {
            return null;
        }

        return match ($reason) {
            'cancelled' => RunActivityStateEnum::Cancelled,
            'failed' => RunActivityStateEnum::Failed,
            default => RunActivityStateEnum::Completed,
        };
    }
}
