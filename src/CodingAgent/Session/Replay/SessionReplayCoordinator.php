<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Application\Replay\RunStateReducer;
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
            $events = $source->log->selectedEvents($source->path, $existing->runId, $cut['sequence'], $cut['anchor']);
            if ($withTranscript) {
                $events = $this->projectAlongside($events);
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

            $result = new SessionReplayResultDTO($state, $withTranscript ? $this->projector->blocks() : [], $cut['end_offset'], $cut['anchor']);
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

    /** @param iterable<RunEvent> $events
     * @return \Generator<int, RunEvent>
     */
    private function projectAlongside(iterable $events): \Generator
    {
        $sizes = [];
        foreach ($events as $event) {
            $runtime = $this->mapper->toRuntimeEvent($event);
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
