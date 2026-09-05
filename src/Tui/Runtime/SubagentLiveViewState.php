<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;

/**
 * State for interactive child live view inside the parent TUI session.
 *
 * Outside agents-live, no child transcript/events are retained. Every live-view
 * entry reconstructs presentation from the child events.jsonl snapshot.
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

    /**
     * Last picker feedback line applied to ChatScreen while the picker is open.
     * Avoids per-tick widget invalidation when the message is unchanged.
     */
    public ?string $lastPickerFeedbackWorkingMessage = null;

    public function isSameChild(SubagentLiveChildDTO $child): bool
    {
        return null !== $this->selected
            && $this->selected->artifactId === $child->artifactId
            && $this->selected->agentRunId === $child->agentRunId;
    }

    public function enter(SubagentLiveChildDTO $child): void
    {
        $this->active = true;
        $this->selected = $child;
        $this->childTranscript = [];
        $this->childLastSeq = 0;
        $this->childLastPoll = 0.0;
        $this->childQueuedUserMessages = [];
        $this->childActivity = $this->activityFromCatalogChild($child);
    }

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
                    'Loading live view for %s · [%s] %s — waiting for child events…',
                    $child->agentName,
                    $child->statusLabel(),
                    $child->artifactId,
                ),
            ),
        ];
    }

    public function clearProjectedState(): void
    {
        $this->childTranscript = [];
        $this->childLastSeq = 0;
        $this->childLastPoll = 0.0;
        $this->childQueuedUserMessages = [];
        $this->childActivity = RunActivityStateEnum::Idle;
        $this->lastLiveWorkingMessage = null;
    }

    public function exit(): void
    {
        $this->active = false;
        $this->selected = null;
        $this->clearProjectedState();
        $this->pickerFeedbackMessage = null;
        $this->lastPickerFeedbackWorkingMessage = null;
    }

    private function activityFromCatalogChild(SubagentLiveChildDTO $child): RunActivityStateEnum
    {
        return match (true) {
            SubagentLiveStatusEnum::WaitingHuman === $child->status => RunActivityStateEnum::WaitingHuman,
            $child->isRunning() => RunActivityStateEnum::Running,
            default => RunActivityStateEnum::Completed,
        };
    }
}
