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
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    /** Running cost estimate in dollars (accumulated from usage). */
    public float $totalCost = 0.0;
    /** Context window size of the current model, or 0 when unknown. */
    public int $contextWindow = 0;
    /** Timestamp when the LLM first starts generating (set on AssistantTextStarted). */
    public float $llmStartTime = 0.0;
    /** Timestamp when the LLM response completes (set on AssistantMessageCompleted). */
    public float $llmEndTime = 0.0;
    public float $sessionStartTime = 0.0;
    public string $cwd = '';
    public string $branch = '';

    public function __construct(
        string $sessionId,
        bool $resuming = false,
    ) {
        $this->sessionId = $sessionId;
        $this->resuming = $resuming;
    }
}
