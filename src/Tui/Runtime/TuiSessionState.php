<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
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

    /**
     * Ordered transcript blocks (plain, un-themed).
     *
     * Prefer {@see replaceTranscript()}, {@see appendTranscriptBlock()}, and
     * {@see applyTranscriptChangeSet()} so the ID→index map stays coherent.
     *
     * Direct public assignment is test/bootstrap-only. Production mutation paths
     * must use the helpers above. The next helper call rebuilds the index when
     * length/first/last checks fail — do not rely on a stale mid-list map.
     *
     * @var list<TranscriptBlock>
     */
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
     * Original user prompt text from /history selection, applied once by TickPollListener
     * into the editor after RunHistoryPositionChanged rebuild. Null when nothing pending.
     */
    public ?string $pendingEditorPromptText = null;

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
     * Live/session-only mutable display state for the transcript.
     *
     * Initialized from TranscriptDisplayConfig.previewsExpandedByDefault
     * at TUI startup. Ctrl+O ({@see \Ineersa\Tui\Listener\PreviewExpansionInputListener}) toggles
     * previewableBlocksExpanded at runtime for this session only.
     * Not persisted to settings or session metadata.
     */
    public TranscriptDisplayState $transcriptDisplayState;

    public SubagentLiveCatalog $subagentLiveCatalog;

    public SubagentLiveViewState $subagentLiveView;

    /**
     * Staged pasted images keyed by placeholder index ([Image #N]).
     * Promoted into .hatfield/sessions/<id>/attachments/ on submit (issue #119).
     *
     * @var array<int, \Ineersa\Tui\ImagePaste\PastedImagePendingDTO>
     */
    public array $pastedImagePendingByIndex = [];

    /** Next sequential pasted image index for this TUI session. */
    public int $nextPastedImageIndex = 1;

    /**
     * Placeholder index with an in-flight clipboard read ([Image #N] inserted, bytes not ready).
     * Scalar only — the reader service owns the Process handle (issue #119).
     */
    public ?int $pastedImagePasteInProgressIndex = null;

    /**
     * Block ID → index into {@see $transcript} for O(1) upsert/lookup.
     *
     * @var array<string, int>
     */
    private array $transcriptIndexById = [];

    public function __construct(
        string $sessionId,
        bool $resuming = false,
    ) {
        $this->sessionId = $sessionId;
        $this->resuming = $resuming;
        $this->usage = new UsageProjection();
        $this->transcriptDisplayState = new TranscriptDisplayState();
        // Catalog is typed-only; wire denorm happens in TuiRuntimeEventApplier.
        $this->subagentLiveCatalog = new SubagentLiveCatalog();
        $this->subagentLiveView = new SubagentLiveViewState();
    }

    /**
     * Run id that currently owns visible HITL / Esc / submit question routing.
     * Parent session in main view; selected child while live view is active.
     */
    public function visibleQuestionOwnerRunId(): string
    {
        if ($this->subagentLiveView->active && null !== $this->subagentLiveView->selected) {
            return $this->subagentLiveView->selected->agentRunId;
        }

        return null !== $this->handle ? $this->handle->runId : $this->sessionId;
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

    /**
     * Replace the entire ordered transcript (bootstrap, resume, history position).
     *
     * @param list<TranscriptBlock> $blocks
     */
    public function replaceTranscript(array $blocks): void
    {
        $this->transcript = [];
        $this->transcriptIndexById = [];
        foreach ($blocks as $block) {
            $this->transcriptIndexById[$block->id] = \count($this->transcript);
            $this->transcript[] = $block;
        }
    }

    /**
     * Append a local UI block (error/system/cancel placeholders) without a full replace.
     */
    public function appendTranscriptBlock(TranscriptBlock $block): void
    {
        $this->rebuildTranscriptIndexIfStale();
        $existingIdx = $this->transcriptIndexById[$block->id] ?? null;
        if (null !== $existingIdx) {
            $this->transcript[$existingIdx] = $block;

            return;
        }

        $this->transcriptIndexById[$block->id] = \count($this->transcript);
        $this->transcript[] = $block;
    }

    /**
     * Pop the last block when it is the ephemeral Processing… placeholder.
     */
    public function removeTrailingProcessingPlaceholder(): void
    {
        $this->rebuildTranscriptIndexIfStale();
        $lastIdx = \count($this->transcript) - 1;
        if ($lastIdx < 0) {
            return;
        }

        $last = $this->transcript[$lastIdx];
        if (TranscriptBlockKindEnum::System !== $last->kind || !str_contains($last->text, 'Processing...')) {
            return;
        }

        array_pop($this->transcript);
        unset($this->transcriptIndexById[$last->id]);
    }

    /**
     * Apply a canonical projector change set.
     *
     * Full replacement rebuilds the ordered list. Incremental mode upserts by ID
     * (O(1) via index map) and removes missing IDs without retaining duplicates.
     *
     * @return bool True when the ordered transcript content changed
     */
    public function applyTranscriptChangeSet(TranscriptChangeSet $changes): bool
    {
        if ($changes->isFull()) {
            $next = $changes->blocks();
            if ($this->transcript === $next) {
                return false;
            }
            $this->replaceTranscript($next);

            return true;
        }

        $this->rebuildTranscriptIndexIfStale();
        $changed = false;

        // Compaction can remove a whole segment. Filter once rather than splice
        // and shift the remaining transcript for every removed block.
        if ([] !== $changes->removals) {
            $removedIds = array_fill_keys($changes->removals, true);
            $next = array_values(array_filter(
                $this->transcript,
                static fn (TranscriptBlock $block): bool => !isset($removedIds[$block->id]),
            ));
            if (\count($next) !== \count($this->transcript)) {
                $this->replaceTranscript($next);
                $changed = true;
            }
        }

        foreach ($changes->upserts as $block) {
            $idx = $this->transcriptIndexById[$block->id] ?? null;
            if (null === $idx) {
                $this->transcriptIndexById[$block->id] = \count($this->transcript);
                $this->transcript[] = $block;
                $changed = true;
                continue;
            }

            // Object identity: ProjectionState preserves references for unchanged IDs.
            if ($this->transcript[$idx] === $block) {
                continue;
            }

            $this->transcript[$idx] = $block;
            $changed = true;
        }

        if (null !== $changes->retentionFloorBlockId) {
            if ($this->dropLocalBlocksBeforeRetentionFloor($changes->retentionFloorBlockId)) {
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Rebuild the ID map when the public list was assigned outside helpers.
     *
     * Cheap structural check: same length + first/last id match. Full rebuild is O(n).
     */
    private function rebuildTranscriptIndexIfStale(): void
    {
        $count = \count($this->transcript);
        if (\count($this->transcriptIndexById) === $count) {
            if (0 === $count) {
                return;
            }
            $first = $this->transcript[0];
            $last = $this->transcript[$count - 1];
            if (($this->transcriptIndexById[$first->id] ?? null) === 0
                && ($this->transcriptIndexById[$last->id] ?? null) === $count - 1
            ) {
                return;
            }
        }

        $this->transcriptIndexById = [];
        foreach ($this->transcript as $idx => $block) {
            $this->transcriptIndexById[$block->id] = $idx;
        }
    }

    /**
     * Drop session-local UI blocks that sit strictly before a retention floor.
     *
     * Local Error / Processing… blocks never enter the runtime projector, so
     * projector ID removals cannot evict them. When compaction advances the
     * rolling window, drop locals that appear before the floor marker while
     * keeping newer locals that arrived after it.
     */
    private function dropLocalBlocksBeforeRetentionFloor(string $floorBlockId): bool
    {
        $this->rebuildTranscriptIndexIfStale();
        $floorIdx = $this->transcriptIndexById[$floorBlockId] ?? null;
        if (null === $floorIdx || $floorIdx <= 0) {
            return false;
        }

        $kept = [];
        for ($i = 0; $i < $floorIdx; ++$i) {
            $block = $this->transcript[$i];
            // Projector prune already removed owned history before the floor,
            // except ToolCall blocks retained for still-visible ToolResults.
            // Anything else still sitting before the floor is session-local UI
            // (or stale) and must leave with the evicted segment.
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $kept[] = $block;
            }
        }

        if (\count($kept) === $floorIdx) {
            return false;
        }

        $this->replaceTranscript([...$kept, ...\array_slice($this->transcript, $floorIdx)]);

        return true;
    }
}
