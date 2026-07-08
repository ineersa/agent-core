<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * State for interactive child live view inside the parent TUI session.
 *
 * Child transcript/seq cache survives {@see exit()} so main/live toggles on the
 * same child can re-show cached blocks without replaying consumed JSONL pipe events.
 */
final class SubagentLiveViewState
{
    public bool $active = false;

    public ?SubagentLiveChildDTO $selected = null;

    /** @var list<TranscriptBlock> */
    public array $childTranscript = [];

    public int $childLastSeq = 0;

    public float $childLastPoll = 0.0;

    /**
     * Per-child transcript/seq cache keyed by agentRunId so switching picker rows
     * does not discard completed transcripts (JSONL pipe events are consumed once).
     *
     * @var array<string, array{transcript: list<TranscriptBlock>, lastSeq: int, lastPoll: float, activity: RunActivityStateEnum, queuedUserMessages: array<string, string>}>
     */
    public array $childCaches = [];

    public RunActivityStateEnum $childActivity = RunActivityStateEnum::Idle;

    /** @var array<string, string> idempotency_key => text */
    public array $childQueuedUserMessages = [];

    /**
     * Last combined parent|child working line pushed to ChatScreen while live view is active.
     * Avoids per-tick widget invalidation when the message is unchanged (terminal flicker).
     */
    public ?string $lastLiveWorkingMessage = null;

    /**
     * Transient picker overlay feedback (e.g. child export path). Shown in the picker header
     * and preserved across tick working-message updates while the picker is open.
     */
    public ?string $pickerFeedbackMessage = null;

    public function isSameChild(SubagentLiveChildDTO $child): bool
    {
        return null !== $this->selected
            && $this->selected->artifactId === $child->artifactId
            && $this->selected->agentRunId === $child->agentRunId;
    }

    /**
     * True when entering this child requires resetting the child projector and cache.
     */
    public function shouldResetProjectionFor(SubagentLiveChildDTO $child): bool
    {
        if (!$this->isSameChild($child)) {
            return true;
        }

        return [] === $this->childTranscript;
    }

    public function persistCurrentChildCache(): void
    {
        if (null === $this->selected || '' === $this->selected->agentRunId) {
            return;
        }

        $this->childCaches[$this->selected->agentRunId] = [
            'transcript' => $this->childTranscript,
            'lastSeq' => $this->childLastSeq,
            'lastPoll' => $this->childLastPoll,
            'activity' => $this->childActivity,
            'queuedUserMessages' => $this->childQueuedUserMessages,
        ];
    }

    public function restoreChildCacheFor(SubagentLiveChildDTO $child): void
    {
        $cached = $this->childCaches[$child->agentRunId] ?? null;
        if (null === $cached) {
            return;
        }

        $this->childTranscript = $cached['transcript'];
        $this->childLastSeq = $cached['lastSeq'];
        $this->childLastPoll = $cached['lastPoll'];
        $this->childActivity = $cached['activity'];
        $this->childQueuedUserMessages = $cached['queuedUserMessages'] ?? [];
    }

    public function enter(SubagentLiveChildDTO $child): void
    {
        $this->active = true;

        if (!$this->isSameChild($child)) {
            $this->persistCurrentChildCache();
        }

        $cached = $this->childCaches[$child->agentRunId] ?? null;
        if (null !== $cached && [] !== $cached['transcript']) {
            $this->selected = $child;
            $this->childTranscript = $cached['transcript'];
            $this->childLastSeq = $cached['lastSeq'];
            $this->childLastPoll = $cached['lastPoll'];
            $this->childActivity = $cached['activity'];

            return;
        }

        if ($this->shouldResetProjectionFor($child)) {
            $this->selected = $child;
            $this->childTranscript = [];
            $this->childLastSeq = 0;
            $this->childLastPoll = 0.0;
            $this->childActivity = match (true) {
                SubagentLiveStatusEnum::WaitingHuman === $child->status => RunActivityStateEnum::WaitingHuman,
                $child->isRunning() => RunActivityStateEnum::Running,
                default => RunActivityStateEnum::Completed,
            };

            return;
        }

        $this->selected = $child;
        if (SubagentLiveStatusEnum::WaitingHuman === $child->status) {
            $this->childActivity = RunActivityStateEnum::WaitingHuman;
        } elseif ($child->isRunning()) {
            $this->childActivity = RunActivityStateEnum::Running;
        } elseif ($child->isTerminal()) {
            $this->childActivity = match ($child->status) {
                SubagentLiveStatusEnum::Completed, SubagentLiveStatusEnum::Done => RunActivityStateEnum::Completed,
                SubagentLiveStatusEnum::Failed => RunActivityStateEnum::Failed,
                SubagentLiveStatusEnum::Cancelled => RunActivityStateEnum::Cancelled,
                default => RunActivityStateEnum::Completed,
            };
        }
    }

    /**
     * Leaves live view UI mode but keeps child cache for fast re-entry.
     */

    /**
     * @return list<TranscriptBlock>
     */
    public function placeholderTranscriptFor(SubagentLiveChildDTO $child): array
    {
        return [
            new TranscriptBlock(
                id: 'subagent-live-placeholder',
                kind: TranscriptBlockKindEnum::Progress,
                runId: $child->agentRunId,
                seq: 0,
                text: \sprintf(
                    'Loading live view for %s [%s] %s — waiting for child events…',
                    $child->agentName,
                    $child->statusLabel(),
                    $child->artifactId,
                ),
            ),
        ];
    }

    public function removeChildCache(string $agentRunId): void
    {
        unset($this->childCaches[$agentRunId]);
    }

    public function exit(): void
    {
        $this->active = false;
        $this->lastLiveWorkingMessage = null;
        $this->pickerFeedbackMessage = null;
        $this->childQueuedUserMessages = [];
    }
}
