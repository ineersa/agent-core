<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Tui;

/**
 * Session switch lifecycle seam for the TUI.
 *
 * Future slash commands (e.g. /new, /resume) call {@see requestResume()}
 * or {@see requestNewDraft()} to trigger a session switch.  The service
 * cancels the current run, resets stateful singletons, records the
 * pending target, and calls {@see Tui::stop()} to exit the current event
 * loop.  InteractiveMode then consumes the pending target and rebuilds
 * TUI/session objects in the same CLI process.
 *
 * Per-iteration bindings ({@see bindForIteration()}) are refreshed each
 * loop by InteractiveMode so that the service always references the
 * current TUI and session objects.
 *
 * Process-scoped dependencies (QuestionCoordinator, QuestionController,
 * TranscriptProjectorInterface, HatfieldSessionStore) are injected once
 * via the constructor.
 */
class TuiSessionSwitchService implements TuiSessionSwitchServiceInterface
{
    // ── Per-iteration bindings (reset each loop) ──
    private ?Tui $tui = null;
    private ?AgentSessionClient $client = null;
    private ?TuiSessionState $state = null;

    // ── Pending switch state (consumed after event loop exits) ──
    private ?string $pendingResumeSessionId = null;
    private ?StartRunRequest $pendingDraftRequest = null;
    private bool $isPendingDraft = false;

    public function __construct(
        private readonly QuestionCoordinator $questionCoordinator,
        private readonly QuestionController $questionController,
        private readonly TranscriptProjectorInterface $projector,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Bind per-iteration references before the event loop starts.
     *
     * Called by InteractiveMode each loop iteration after creating
     * fresh Tui / TuiSessionState objects but before registering
     * listeners.
     */
    public function bindForIteration(
        Tui $tui,
        AgentSessionClient $client,
        TuiSessionState $state,
    ): void {
        $this->tui = $tui;
        $this->client = $client;
        $this->state = $state;
    }

    /**
     * Request a switch to an existing session by ID.
     *
     * Cancels the current run (if active), resets question/overlay
     * state, and records the target session ID.  The TUI event loop
     * is stopped; InteractiveMode picks up the target on the next
     * loop iteration.
     */
    public function requestResume(string $sessionId): void
    {
        $this->cancelCurrentRun();
        $this->resetLocalState();
        $this->pendingResumeSessionId = $sessionId;
        $this->isPendingDraft = false;
        $this->tui?->stop();
    }

    /**
     * Request a switch to a fresh draft session.
     *
     * Same cancel/reset semantics as {@see requestResume()} but
     * targets a lazy draft — no DB session row is created until
     * the first user-submitted message (see SubmitListener).
     *
     * @param StartRunRequest|null $request Optional pre-configured
     *                                      request (e.g. from /new --model).
     */
    public function requestNewDraft(?StartRunRequest $request = null): void
    {
        $this->cancelCurrentRun();
        $this->resetLocalState();
        $this->pendingDraftRequest = $request;
        $this->isPendingDraft = true;
        $this->tui?->stop();
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

    /**
     * True when a pending switch has been requested but not yet consumed.
     */
    public function hasPendingSwitch(): bool
    {
        return $this->isPendingDraft || null !== $this->pendingResumeSessionId;
    }

    // ── Private helpers ──

    /**
     * Cancel the currently active run, if any.
     *
     * Best-effort: if the run is already terminal or cancel fails,
     * a structured diagnostic is logged and the session switch proceeds.
     * The switch must never be blocked by a failed cancellation.
     */
    private function cancelCurrentRun(): void
    {
        if (null === $this->state?->handle || null === $this->client) {
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

    /**
     * Reset stateful singletons so the next session starts clean.
     *
     * - QuestionCoordinator: clear active/queued questions and callbacks.
     * - QuestionController: close any open overlay.
     * - TranscriptProjector: clear projected blocks from the old session.
     */
    private function resetLocalState(): void
    {
        $this->questionCoordinator->reset();
        $this->questionController->close();
        $this->projector->reset();
    }
}
