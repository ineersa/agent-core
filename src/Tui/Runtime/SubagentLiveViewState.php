<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;

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

    public int $childLastSeq = 0;

    public float $childLastPoll = 0.0;

    public RunActivityStateEnum $childActivity = RunActivityStateEnum::Idle;

    /** Transient child LLM retry working-status override from llm.request_retrying. */
    public ?string $llmRetryWorkingMessage = null;

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

    // Reuse the session's indexed transcript application, not a second ID-map
    // implementation. Lazy construction avoids recursively creating live views.
    private ?TuiSessionState $transcriptState = null;

    /** @var list<string> Blocks mounted only until the first live batch applies. */
    private array $entryBlockIds = [];

    public function enter(SubagentLiveChildDTO $child): void
    {
        $this->active = true;
        $this->selected = $child;
        $this->transcriptState = null;
        $this->entryBlockIds = [];
        $this->childLastSeq = 0;
        $this->childLastPoll = 0.0;
        $this->childQueuedUserMessages = [];
        $this->childActivity = $this->activityFromCatalogChild($child);
        $this->llmRetryWorkingMessage = null;
    }

    /**
     * @return list<TranscriptBlock>
     */
    public function childTranscript(): array
    {
        return $this->transcriptState->transcript ?? [];
    }

    /** @param list<TranscriptBlock> $blocks */
    public function replaceChildTranscript(array $blocks, bool $entryOnly = false): void
    {
        $this->transcriptState ??= new TuiSessionState($this->selected->agentRunId ?? '');
        $this->transcriptState->replaceTranscript($blocks);
        $this->entryBlockIds = $entryOnly ? array_column($blocks, 'id') : [];
    }

    public function appendChildTranscriptBlock(TranscriptBlock $block): void
    {
        $this->transcriptState ??= new TuiSessionState($this->selected->agentRunId ?? '');
        $this->transcriptState->appendTranscriptBlock($block);
    }

    public function applyTranscriptChangeSet(TranscriptChangeSet $changes): TranscriptChangeSet
    {
        if (!$changes->isFull() && [] !== $this->entryBlockIds) {
            $changes = TranscriptChangeSet::incremental(
                $changes->upserts,
                array_values(array_unique([...$changes->removals, ...$this->entryBlockIds])),
                $changes->retentionFloorBlockId,
            );
        }
        $this->entryBlockIds = [];
        if (!$changes->isEmpty()) {
            $this->transcriptState ??= new TuiSessionState($this->selected->agentRunId ?? '');
            $this->transcriptState->applyTranscriptChangeSet($changes);
        }

        // Retention also evicts local notices unknown to the runtime projector.
        // Publish the authoritative list at this boundary, as in the main view.
        return null !== $changes->retentionFloorBlockId
            ? TranscriptChangeSet::full($this->childTranscript())
            : $changes;
    }

    /** @return list<TranscriptBlock> */
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
        $this->transcriptState = null;
        $this->entryBlockIds = [];
        $this->childLastSeq = 0;
        $this->childLastPoll = 0.0;
        $this->childQueuedUserMessages = [];
        $this->childActivity = RunActivityStateEnum::Idle;
        $this->lastLiveWorkingMessage = null;
        $this->llmRetryWorkingMessage = null;
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
        // Fresh entry uses Completed for all inactive statuses until replay/poll
        // reconciles the exact lifecycle; unlike toActivity(), Unknown is not preserved.
        return match (true) {
            SubagentLiveStatusEnum::WaitingHuman === $child->status => RunActivityStateEnum::WaitingHuman,
            $child->isRunning() => RunActivityStateEnum::Running,
            default => RunActivityStateEnum::Completed,
        };
    }
}
