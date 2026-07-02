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

    public RunActivityStateEnum $childActivity = RunActivityStateEnum::Idle;

    /**
     * Last combined parent|child working line pushed to ChatScreen while live view is active.
     * Avoids per-tick widget invalidation when the message is unchanged (terminal flicker).
     */
    public ?string $lastLiveWorkingMessage = null;

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

    public function enter(SubagentLiveChildDTO $child): void
    {
        $this->active = true;

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
        } elseif (!$this->childActivity->isActive()) {
            $this->childActivity = RunActivityStateEnum::Completed;
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

    public function exit(): void
    {
        $this->active = false;
        $this->lastLiveWorkingMessage = null;
    }
}
