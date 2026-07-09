<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;

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

    /**
     * When the user submits a message while the run is Cancelling, the
     * message text is stored here.  It is dispatched as a follow_up
     * only after the RuntimeEventPoller observes the Cancelling→Cancelled
     * transition, avoiding race conditions where steer/follow_up commands
     * are rejected by AgentCore during the Cancelling grace window.
     */
    public ?string $queuedFollowUp = null;

    /**
     * Steer/follow-up messages queued by AgentCore while the run is active.
     * Keyed by idempotency_key; value is the message text.
     *
     * Driven by applyQueuedUserMessageEvent(), called from both
     * RuntimeEventPoller and SessionInitializer::replayFromEvents. Rendered by
     * the PendingMessagesWidget above the editor until the canonical user
     * message is applied to the run, at which point the entry pops and the
     * finalized ❯ user message is appended to the transcript.
     *
     * @var array<string, string>
     */
    public array $queuedUserMessages = [];

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
     * Whether a compaction is currently in progress for the active
     * run. Set by CompactCommandHandler (manual /compact) and by
     * RuntimeEventPoller (auto compaction via CompactionStarted event).
     * Cleared by RuntimeEventPoller when a compaction.completed or
     * compaction.failed event arrives.
     */
    public bool $isCompacting = false;

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

    /**
     * TUI-local immutable transcript display config for the current session.
     *
     * Set by InteractiveMode during TUI startup from Hatfield config via
     * TranscriptDisplayConfigMapper. Defaults are safe for test factories
     * and manual construction.
     */
    public TranscriptDisplayConfig $transcriptDisplayConfig;

    /**
     * Live/session-only mutable display state for the transcript.
     *
     * Initialized from transcriptDisplayConfig.previewsExpandedByDefault
     * at TUI startup. Ctrl+O ({@see \Ineersa\Tui\Listener\PreviewExpansionInputListener}) toggles
     * previewableBlocksExpanded at runtime for this session only.
     * Not persisted to settings or session metadata.
     */
    public TranscriptDisplayState $transcriptDisplayState;

    public SubagentLiveCatalog $subagentLiveCatalog;

    public SubagentLiveViewState $subagentLiveView;

    /** Last background child-stream poll timestamp (main view only). */
    public float $subagentLiveBackgroundLastPoll = 0.0;

    /** @var array<string, int> agentRunId => last drained seq for background child polling */
    public array $subagentLiveBackgroundSeqByRunId = [];

    public function __construct(
        string $sessionId,
        bool $resuming = false,
    ) {
        $this->sessionId = $sessionId;
        $this->resuming = $resuming;
        $this->usage = new UsageProjection();
        $this->transcriptDisplayConfig = new TranscriptDisplayConfig();
        $this->transcriptDisplayState = new TranscriptDisplayState();
        $this->subagentLiveCatalog = new SubagentLiveCatalog();
        $this->subagentLiveView = new SubagentLiveViewState();
    }

    /**
     * Apply a queued-user-message runtime event to the pending-queue state.
     *
     * Pushes user.message_queued entries (keyed by idempotency_key, value =
     * message text) and pops the matching entry on user.message_submitted.
     * The PendingMessagesWidget above the editor renders the pushed entries as
     * "⏳ <text>" until the canonical ❯ user message is applied to the run.
     *
     * Called from BOTH the live RuntimeEventPoller and
     * SessionInitializer::replayFromEvents so the pending-queue widget is
     * rebuilt correctly after resume (e.g. a steer queued while the run is
     * active must still show ⏳ after the TUI is closed and reopened).
     */
    public function applyQueuedUserMessageEvent(RuntimeEvent $event): void
    {
        if (RuntimeEventTypeEnum::UserMessageQueued->value === $event->type) {
            $key = (string) ($event->payload['idempotency_key'] ?? '');
            if ('' !== $key) {
                $this->queuedUserMessages[$key] = (string) ($event->payload['text'] ?? '');
            }
        } elseif (RuntimeEventTypeEnum::UserMessageSubmitted->value === $event->type) {
            $key = (string) ($event->payload['idempotency_key'] ?? '');
            if ('' !== $key && isset($this->queuedUserMessages[$key])) {
                unset($this->queuedUserMessages[$key]);
            }
        }
    }
}
