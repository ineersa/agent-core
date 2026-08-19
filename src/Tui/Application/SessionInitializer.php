<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Psr\Log\LoggerInterface;

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
        private SessionRunEventStore $eventStore,
        private TranscriptBlockFactory $blockFactory,
        private LoggerInterface $logger,
        private HistoryProviderInterface $historyProvider,
        private SessionTranscriptProviderInterface $sessionTranscriptProvider,
        private ?AiCatalog $aiCatalog = null,
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

        $blocks = [$this->blockFactory->system(
            runId: $runId,
            text: 'Welcome to Hatfield. Type a message below to start.',
            seq: 1,
        )];

        // Visible once per fresh session start when the bundled catalog is newer
        // than the user's copy. Resume paths skip this — no second notice on attach.
        if (true === $this->aiCatalog?->isBundledNewerThanUser()) {
            $blocks[] = $this->blockFactory->system(
                runId: $runId,
                text: 'AI catalog update available — run bin/console providers:update',
                seq: 2,
                style: 'warning',
            );
        }

        return $blocks;
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
            $runEvents = $this->eventStore->allFor($runId);
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

        if ([] === $runEvents) {
            return [$this->blockFactory->system(
                runId: $runId,
                text: 'Session '.$runId.' — no messages yet.',
                seq: 1,
            )];
        }

        $maxSourceSeq = 0;

        // Compute the full-stream maximum seq first (for lastSeq correctness).
        foreach ($runEvents as $runEvent) {
            if ($runEvent->seq > $maxSourceSeq) {
                $maxSourceSeq = $runEvent->seq;
            }
        }

        // Fail-closed retained-history resume: always use provider + transcript at the
        // explicit int position (0 = before first / empty). Never fall back to replaying
        // the full canonical stream — that can resurrect discarded tail content.
        // Projection/provider failures propagate to outer error handling.
        $history = $this->historyProvider->forSession($runId);
        $positionTurnNo = $history->positionTurnNo;
        $snapshot = $this->sessionTranscriptProvider->transcriptAtPosition(
            $runId,
            $positionTurnNo,
        );
        $historyAwareBlocks = $snapshot->transcriptBlocks;

        foreach ($snapshot->replayEvents as $runtimeEvent) {
            $eventApplier->apply($state, $runtimeEvent, replayMode: true);
        }

        // Set lastSeq so the live poller does not re-process replayed events.
        // Always derived from the full canonical stream max, never regressed.
        $state->lastSeq = $maxSourceSeq;

        if ($state->isShellRun = $this->inferShellOnlySessionFromCanonicalEvents($runEvents)) {
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
        $terminalActivity = $this->inferTerminalActivityFromCanonicalEvents($runEvents);
        if (null !== $terminalActivity
            && !$this->shouldSuppressTerminalActivityForInProgressCompaction($runEvents)) {
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
     * Infer terminal TUI activity from the latest canonical agent_end on the full stream.
     *
     * Retained-history replay may omit the terminal agent_end from the position prefix while
     * the hot RunState (and user expectation) is already cancelled/completed/failed.
     * Without this, passive resume can leave activity=Idle while SubmitListener later
     * sets Starting on follow_up, producing a stuck ◐ Working... with no live work.
     *
     * @param list<RunEvent> $runEvents
     */
    private function inferTerminalActivityFromCanonicalEvents(array $runEvents): ?RunActivityStateEnum
    {
        for ($index = \count($runEvents) - 1; $index >= 0; --$index) {
            $runEvent = $runEvents[$index];
            if ('agent_end' !== $runEvent->type) {
                continue;
            }

            $reason = \is_string($runEvent->payload['reason'] ?? null)
                ? $runEvent->payload['reason']
                : 'completed';

            return match ($reason) {
                'cancelled' => RunActivityStateEnum::Cancelled,
                'failed' => RunActivityStateEnum::Failed,
                default => RunActivityStateEnum::Completed,
            };
        }

        return null;
    }

    /**
     * Passive resume after a turn's agent_end may still have an in-flight compaction
     * (context_compaction_started without compacted/failed). Inferring terminal
     * activity from the earlier agent_end would show Completed while attach does not
     * continue compaction — the passive Compacting→Idle normalization must win.
     *
     * @param list<RunEvent> $runEvents
     */
    private function shouldSuppressTerminalActivityForInProgressCompaction(array $runEvents): bool
    {
        $lastCompactionStartedSeq = null;
        $lastCompactionTerminalSeq = null;
        $lastAgentEndSeq = null;

        foreach ($runEvents as $runEvent) {
            $seq = $runEvent->seq;

            if ('context_compaction_started' === $runEvent->type) {
                $lastCompactionStartedSeq = $seq;
            }

            if (\in_array($runEvent->type, ['context_compacted', 'context_compaction_failed'], true)) {
                $lastCompactionTerminalSeq = null === $lastCompactionTerminalSeq
                    ? $seq
                    : max($lastCompactionTerminalSeq, $seq);
            }

            if ('agent_end' === $runEvent->type) {
                $lastAgentEndSeq = $seq;
            }
        }

        if (null === $lastCompactionStartedSeq) {
            return false;
        }

        if (null !== $lastCompactionTerminalSeq
            && $lastCompactionTerminalSeq >= $lastCompactionStartedSeq) {
            return false;
        }

        return $lastCompactionStartedSeq >= ($lastAgentEndSeq ?? 0);
    }

    /**
     * Detect first-input shell-only sessions from canonical events (no run_started / LLM steps).
     *
     * @param list<RunEvent> $runEvents
     */
    private function inferShellOnlySessionFromCanonicalEvents(array $runEvents): bool
    {
        $hasBashTool = false;
        $hasLlmConversation = false;
        $terminalCompleted = false;

        foreach ($runEvents as $runEvent) {
            $type = $runEvent->type;
            $payload = $runEvent->payload;

            if ('run_started' === $type || 'llm_step_completed' === $type) {
                $hasLlmConversation = true;
            }

            if ('tool_execution_start' === $type && 'bash' === (string) ($payload['tool_name'] ?? '')) {
                $hasBashTool = true;
            }

            if ('agent_end' === $type && 'completed' === (string) ($payload['reason'] ?? '')) {
                $terminalCompleted = true;
            }
        }

        return $hasBashTool && !$hasLlmConversation && $terminalCompleted;
    }
}
