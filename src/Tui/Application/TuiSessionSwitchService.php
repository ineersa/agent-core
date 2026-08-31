<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ProcessReloadIntentDTO;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Tui;

/**
 * Session switch lifecycle seam for one TUI session iteration.
 *
 * Constructed per session iteration with the iteration's Tui,
 * AgentSessionClient, and TuiSessionState bound in the constructor —
 * no late rebinding.  Slash commands (/new, /resume) call
 * {@see requestResume()} or {@see requestNewDraft()} to trigger a
 * session switch: the service cancels the current run, records the
 * pending target, and calls {@see Tui::stop()} to exit the current
 * event loop.  InteractiveMode then consumes the pending target and
 * rebuilds TUI/session objects in the same CLI process.
 *
 * Question/overlay/projector cleanup for the old session is implicit:
 * the next iteration composes fresh QuestionCoordinator,
 * QuestionController, and transcript projector instances, so no
 * reset choreography is needed here.
 */
class TuiSessionSwitchService implements TuiSessionSwitchServiceInterface
{
    // ── Pending switch state (consumed after event loop exits) ──
    private ?string $pendingResumeSessionId = null;
    private ?StartRunRequest $pendingDraftRequest = null;
    private bool $isPendingDraft = false;
    private ?ProcessReloadIntentDTO $pendingReload = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly AgentSessionClient $client,
        private readonly TuiSessionState $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Request a switch to an existing session by ID.
     *
     * Cancels the current run (if active) and records the target
     * session ID.  The TUI event loop is stopped; InteractiveMode
     * picks up the target on the next loop iteration.
     */
    public function requestResume(string $sessionId): void
    {
        $this->cancelCurrentRun();
        $this->pendingResumeSessionId = $sessionId;
        $this->isPendingDraft = false;
        $this->tui->stop();
    }

    /**
     * Request a switch to a fresh draft session.
     *
     * Same cancel semantics as {@see requestResume()} but targets a
     * lazy draft — no DB session row is created until the first
     * user-submitted message (see SubmitListener).
     *
     * @param StartRunRequest|null $request Optional pre-configured
     *                                      request (e.g. from /new --model).
     */
    public function requestNewDraft(?StartRunRequest $request = null): void
    {
        $this->cancelCurrentRun();
        $this->pendingDraftRequest = $request;
        $this->isPendingDraft = true;
        $this->tui->stop();
    }

    /**
     * Request a full-process settings reload for the current session.
     *
     * Stores the typed reload intent (current persisted session ID) and
     * stops the TUI event loop. Does NOT cancel the current run — the
     * /reload handler guarantees the run is idle/terminal before this is
     * called (unlike requestResume/requestNewDraft, which may cancel an
     * active run as part of a switch).
     */
    public function requestReload(string $sessionId): void
    {
        $this->pendingReload = new ProcessReloadIntentDTO($sessionId);
        $this->tui->stop();
    }

    /**
     * Consume and return the pending reload intent, if any.
     *
     * Called by InteractiveMode right after the event loop exits, BEFORE
     * consumePendingSwitch(), so a reload always wins over a stale switch.
     */
    public function consumePendingReload(): ?ProcessReloadIntentDTO
    {
        $pending = $this->pendingReload;
        $this->pendingReload = null;

        return $pending;
    }

    /**
     * Consume and return the pending switch target, if any.
     *
     * Called by InteractiveMode after the event loop exits.
     * Returns null when no switch was requested (normal exit).
     */
    public function consumePendingSwitch(): ?TuiSessionSwitchTargetDTO
    {
        if ($this->isPendingDraft) {
            $target = new TuiSessionSwitchTargetDTO(
                isDraft: true,
                sessionId: null,
                request: $this->pendingDraftRequest,
            );
        } elseif (null !== $this->pendingResumeSessionId) {
            $target = new TuiSessionSwitchTargetDTO(
                isDraft: false,
                sessionId: $this->pendingResumeSessionId,
                request: null,
            );
        } else {
            return null;
        }

        $this->pendingResumeSessionId = null;
        $this->pendingDraftRequest = null;
        $this->isPendingDraft = false;

        return $target;
    }

    
    public function selectHistoryTurn(int $targetTurnNo): void
    {
        if (null === $this->state->handle) {
            throw new \RuntimeException('Cannot select history: no active session or run handle.');
        }

        $runId = $this->state->handle->runId;

        // Cancel the current run, if active.
        $this->cancelCurrentRun();

        // Dispatch the history-select command.
        $this->client->send($runId, new UserCommand(
            type: 'select_history_turn',
            payload: ['turn_no' => $targetTurnNo],
        ));

        // Do NOT reset local state here — that happens reactively when the
        // RunHistoryPositionChanged RuntimeEvent arrives via RuntimeEventPoller, so we
        // rebuild from the authoritative server-side replay, not speculatively.
        // The poller will replace transcript, reset lastSeq, and update activity.
    }

    // ── Private helpers ──

    /**
     * Cancel the currently active run, if any and if active.
     *
     * Only cancels runs that are actively processing (Starting, Running,
     * WaitingHuman, Cancelling).  Terminal runs (Completed, Failed,
     * Cancelled) are left untouched — sending a cancel to an already-
     * terminal run would transition it to Cancelling and poison the run
     * state, blocking all future resume / follow_up / steer commands.
     *
     * Best-effort: if cancel fails, a structured diagnostic is logged
     * and the session switch proceeds — the switch must never be
     * blocked by a failed cancellation.
     */
    private function cancelCurrentRun(): void
    {
        if (null === $this->state->handle) {
            return;
        }

        // Never cancel terminal runs — they are already done and the
        // cancel would poison them with a Cancelling status, blocking
        // all future commands (continue, follow_up, steer).
        if ($this->state->activity->isTerminal()) {
            return;
        }

        $runId = $this->state->handle->runId;

        try {
            $this->client->cancel($runId);
        } catch (\Throwable $e) {
            // Best effort — log the failure so operators can diagnose
            // but do not block the session switch.
            $this->logger->warning('Session switch: cancel of previous run failed', [
                'component' => 'TuiSessionSwitchService',
                'event_type' => 'switch_cancel_failed',
                'run_id' => $runId,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
        }
    }
}
