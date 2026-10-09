<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Psr\Log\LoggerInterface;

/** One indexed body traversal feeds execution and optional display reducers. */
final readonly class SessionReplayCoordinator
{
    private const int VIEW_BYTES = 4 * 1024 * 1024;
    private const int VIEW_BLOCKS = 2000;

    public function __construct(
        private RunHistorySourceProviderInterface $eventStore,
        private RunStateReducer $reducer,
        private RuntimeEventMapper $mapper,
        private TranscriptProjectorInterface $projector,
        private LoggerInterface $logger,
        private CommandStoreInterface $commands,
    ) {
    }

    public function reconstruct(RunState $existing, ?int $positionTurnNo = null, bool $withTranscript = false): ?SessionReplayResultDTO
    {
        $source = $this->eventStore->historySource($existing->runId);
        $cut = $source->log->historyCut($source->path, $existing->runId, $positionTurnNo);
        if (null === $cut) {
            return null;
        }
        $this->projector->reset();
        try {
            $resume = $withTranscript ? new SessionResumeMetadataProjection() : null;
            $events = $source->log->selectedEvents($source->path, $existing->runId, $cut['sequence'], $cut['anchor']);
            if ($withTranscript) {
                $events = $this->projectAlongside($events, $resume);
            }
            $state = $this->reducer->replay($existing, $events)->with(['lastSeq' => $cut['sequence']]);
            if (null !== $positionTurnNo) {
                // Historical conversation is not a grant to resume archived work.
                // The committed history_select marker preserves this fence on cold recovery.
                $state = $state->with([
                    'turnNo' => $positionTurnNo,
                    'status' => RunStatus::Queued === $state->status ? RunStatus::Queued : RunStatus::Completed,
                    'currentOperation' => null,
                    'currentToolCalls' => [],
                    'pendingToolCalls' => [],
                    'pendingShellToolCalls' => [],
                    'pendingHumanInputRequests' => [],
                ]);
            }

            $result = new SessionReplayResultDTO($state, $withTranscript ? $this->projector->blocks() : [], $cut['end_offset'], $cut['anchor'], $resume?->toArray() ?? []);
            $this->logger->debug('session_replay.reconstructed', [
                'run_id' => $state->runId, 'session_id' => $state->runId,
                'component' => 'session_replay', 'event_type' => 'session_replay.reconstructed',
                'canonical_sequence' => $state->lastSeq, 'end_offset' => $result->endOffset,
                'selected_anchor' => $result->anchor, 'with_transcript' => $withTranscript,
            ]);

            return $result;
        } finally {
            // A failed read admits nothing. Successful products leave the temporary
            // projector too; the caller owns only the returned immutable blocks.
            $this->projector->reset();
        }
    }

    /** Warm attach projects history without reconstructing or replacing the owner's state. */
    public function display(RunState $current): SessionReplayResultDTO
    {
        $source = $this->eventStore->historySource($current->runId);
        $cut = $source->log->historyCut($source->path, $current->runId);
        if (null === $cut || $current->lastSeq !== $cut['sequence']) {
            throw new \RuntimeException('Warm bootstrap requires the current canonical owner state.');
        }
        $this->projector->reset();
        try {
            $resume = new SessionResumeMetadataProjection();
            foreach ($this->projectAlongside($source->log->selectedEvents($source->path, $current->runId, $cut['sequence'], $cut['anchor']), $resume) as $event) {
                unset($event);
            }

            return new SessionReplayResultDTO($current, $this->projector->blocks(), $cut['end_offset'], $cut['anchor'], $resume->toArray());
        } finally {
            $this->projector->reset();
        }
    }

    /**
     * Explicit display-only history read, with no execution-state reconstruction.
     *
     * @return list<TranscriptBlock>
     */
    public function transcriptAtPosition(string $runId, int $positionTurnNo): array
    {
        $source = $this->eventStore->historySource($runId);
        $cut = $source->log->historyCut($source->path, $runId, $positionTurnNo);
        if (null === $cut) {
            return [];
        }
        $this->projector->reset();
        try {
            foreach ($this->projectAlongside($source->log->selectedEvents($source->path, $runId, $cut['sequence'], $cut['anchor']), null) as $event) {
                unset($event);
            }

            return $this->projector->blocks();
        } finally {
            $this->projector->reset();
        }
    }

    /** @param list<TranscriptBlock> $blocks
     * @return list<TranscriptBlock> */
    public function extendDisplay(array $blocks, RunEvent $committed, SessionResumeMetadataProjection $resume): array
    {
        $this->projector->replaceProjectedBlocks($blocks);
        try {
            foreach ($this->projectAlongside([$committed], $resume) as $event) {
                unset($event);
            }

            return $this->projector->blocks();
        } finally {
            $this->projector->reset();
        }
    }

    /** @param iterable<RunEvent> $events
     * @return \Generator<int, RunEvent>
     */
    private function projectAlongside(iterable $events, ?SessionResumeMetadataProjection $resume): \Generator
    {
        $sizes = [];
        $hasPending = null;
        foreach ($events as $event) {
            $hasPending ??= null !== $resume && $this->commands->countPending($event->runId) > 0;
            $runtime = $this->mapper->toRuntimeEvent($event);
            // Completed mailbox rows are deleted. Consult current pending evidence
            // before retaining text, rather than accumulating historical queues.
            $pending = $hasPending && 'user.message_queued' === $runtime?->type
                && $this->commands->has($event->runId, (string) ($runtime->payload['idempotency_key'] ?? ''));
            if ($pending) {
                $source = $this->eventStore->historySource($event->runId);
                $pending = $source->log->isLatestCommand($source->path, $event->runId, (string) $runtime->payload['idempotency_key'], $event->seq);
            }
            $resume?->observe($event, $runtime, $pending);
            if (null !== $runtime) {
                $this->projector->accept($runtime);
                $this->projector->drainChanges();
                $this->boundView($sizes);
            }
            unset($runtime);
            yield $event;
            unset($event);
        }
    }

    /** @param array<string, array{reference: \WeakReference<TranscriptBlock>, bytes: int}> $sizes Bounded display-only accounting without owning old blocks. */
    private function boundView(array &$sizes): void
    {
        $blocks = $this->projector->blocks();
        $bytes = 2 + max(0, \count($blocks) - 1);
        $current = [];
        foreach ($blocks as $block) {
            $size = ($sizes[$block->id]['reference'] ?? null)?->get() === $block ? $sizes[$block->id]['bytes'] : \strlen(json_encode($block, \JSON_THROW_ON_ERROR));
            $current[$block->id] = ['reference' => \WeakReference::create($block), 'bytes' => $size];
            $bytes += $size;
        }
        $sizes = $current;
        while ($bytes > self::VIEW_BYTES || \count($blocks) > self::VIEW_BLOCKS) {
            $boundary = null;
            foreach ($blocks as $index => $block) {
                $previous = $blocks[$index - 1] ?? null;
                $endsExchange = null !== $previous && \in_array($previous->kind, [TranscriptBlockKindEnum::ToolResult, TranscriptBlockKindEnum::Question, TranscriptBlockKindEnum::Approval], true);
                if ($index > 0 && (TranscriptBlockKindEnum::UserMessage === $block->kind || $endsExchange) && $this->isClosedPrefix($blocks, $index)) {
                    $boundary = $index;
                    break;
                }
            }
            if (null === $boundary) {
                throw new \LengthException('Transcript display group exceeds the bootstrap view budget.');
            }
            foreach (\array_slice($blocks, 0, $boundary) as $block) {
                $bytes -= $sizes[$block->id]['bytes'] + 1;
                unset($sizes[$block->id]);
            }
            $blocks = \array_slice($blocks, $boundary);
            $this->projector->replaceProjectedBlocks($blocks);
        }
    }

    /** @param list<TranscriptBlock> $blocks */
    private function isClosedPrefix(array $blocks, int $boundary): bool
    {
        $calls = [];
        foreach (\array_slice($blocks, 0, $boundary) as $block) {
            $id = $block->meta['tool_call_id'] ?? $block->id;
            if (TranscriptBlockKindEnum::ToolCall === $block->kind) {
                $calls[$id] = true;
            } elseif (TranscriptBlockKindEnum::ToolResult === $block->kind) {
                if ($block->streaming) {
                    return false;
                }
                unset($calls[$id]);
            } elseif ($block->streaming || (\in_array($block->kind, [TranscriptBlockKindEnum::Question, TranscriptBlockKindEnum::Approval], true)
                && !\in_array($block->meta['status'] ?? null, ['answered', 'approved', 'rejected'], true))) {
                return false;
            }
        }

        return [] === $calls;
    }
}
