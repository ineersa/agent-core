<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;

/**
 * Mutable state bag for the interactive TUI session.
 *
 * Replaces the previous pattern of 6+ variables captured by reference (&)
 * across anonymous closures. All listeners share a single $state instance,
 * making the control flow explicit and the listeners testable.
 *
 * Transcript blocks are stored as plain projection DTOs; rendering
 * (theme colors, prefixes) is applied by ChatScreen at display time.
 *
 * Footer usage/token state lives in the UsageProjection sub-object,
 * which enforces per-turn reset and session-level accumulation invariants.
 */
final class TuiSessionState
{
    public string $sessionId;
    public bool $resuming;

    public ?RunHandle $handle = null;
    public ?StartRunRequest $request = null;

    /**
     * Authoritative TUI activity state for the current run.
     *
     * Updated by SubmitListener (on send/start/cancel) and by
     * RuntimeEventPoller (on each poll from runtime events).
     * Replaces the prior getWorkingMessage() heuristic.
     */
    public RunActivityStateEnum $activity = RunActivityStateEnum::Idle;

    /**
     * Whether the current run was created by a shell-only ! command
     * (shellExecute) rather than by a normal LLM start().  Only set
     * for first-input standalone shell commands; subsequent ! commands
     * during an active run keep the existing run identity.
     *
     * Used by SubmitListener to decide whether to send a follow_up
     * (normal multi-turn) or start a fresh run (after a completed
     * shell-only run whose runner was never initialised via start()).
     */
    public bool $isShellRun = false;

    /** @var list<TranscriptBlock> Transcript blocks (plain, un-themed) */
    public array $transcript = [];

    public int $lastSeq = 0;
    public float $lastPoll = 0.0;

    /** Number of consecutive runtime polling errors. Resets after a successful poll. */
    public int $runtimePollErrorCount = 0;

    /** Last runtime polling error message surfaced/logged for diagnostics. */
    public string $lastRuntimePollError = '';

    // ── Footer/runtime projection state ──
    // Updated by FooterStateListener on each poll.
    public string $footerModel = '';
    public string $footerReasoning = '';
    /** Context window size of the current model, or 0 when unknown. */
    public int $contextWindow = 0;

    /**
     * Usage/token projection for the TUI footer.
     *
     * Holds both session-level accumulated metrics (inputTokens, outputTokens,
     * totalCost) and per-turn metrics (turnOutputTokens, turnStartTime,
     * llmEndTime, latestInputTokens). Per-turn fields are reset on each
     * TurnStarted event; session fields accumulate across the entire session.
     */
    public UsageProjection $usage;

    public float $sessionStartTime = 0.0;
    public string $cwd = '';
    public string $branch = '';

    public function __construct(
        string $sessionId,
        bool $resuming = false,
    ) {
        $this->sessionId = $sessionId;
        $this->resuming = $resuming;
        $this->usage = new UsageProjection();
    }
}
