<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Session;

use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\TranscriptEntry;
use Psr\Log\LoggerInterface;

/**
 * Feeds runtime events to the TranscriptProjector and persists finalized
 * (non-streaming) transcript blocks to the session transcript.jsonl file.
 *
 * In controller/headless mode, there is no TUI to drive the projector.
 * This service bridges the gap by feeding canonical and transient events
 * to the projector and flushing completed blocks to persistent storage.
 *
 * Tracks the last-persisted block count per run so only new/changed blocks
 * are written on each persist() call, avoiding duplication on repeated
 * event-drain ticks.
 *
 * @see \Ineersa\CodingAgent\Runtime\Controller\HeadlessController
 * @see \Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector
 */
final class TranscriptPersistenceService
{
    /** @var array<string, int> runId => lastPersistedBlockCount */
    private array $persistedBlockCounts = [];

    public function __construct(
        private readonly TranscriptProjectorInterface $projector,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Feed a RuntimeEvent to the transcript projector.
     *
     * Call this for every event that passes through the controller's
     * event drain or LLM stdout poll so the projection stays in sync.
     */
    public function feed(RuntimeEvent $event): void
    {
        $this->projector->accept($event->toArray());
    }

    /**
     * Persist new finalized (non-streaming) transcript blocks for the given run.
     *
     * Only blocks that have not yet been persisted are written. Blocks that
     * are still streaming are skipped — they will be written on a future
     * persist() call when they become finalized.
     */
    public function persist(string $runId): void
    {
        $blocks = $this->projector->blocks();
        $lastCount = $this->persistedBlockCounts[$runId] ?? 0;
        $newBlocks = \array_slice($blocks, $lastCount);

        if ([] === $newBlocks) {
            return;
        }

        foreach ($newBlocks as $block) {
            // Only persist finalized (non-streaming) blocks.
            // Streaming blocks in progress will be written when they finalize.
            if ($block->streaming) {
                continue;
            }

            $entry = $this->blockToEntry($block);

            try {
                $this->sessionStore->appendTranscriptEntry($runId, $entry);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist transcript block', [
                    'run_id' => $runId,
                    'block_id' => $block->id,
                    'block_kind' => $block->kind->value,
                    'exception' => $e,
                ]);
            }
        }

        // Advance the counter past ALL processed blocks (including streaming
        // ones we skipped) so we don't reconsider them next tick.
        $this->persistedBlockCounts[$runId] = \count($blocks);
    }

    /**
     * Reset persisted-block tracking, typically on a fresh start/replay.
     */
    public function reset(string $runId): void
    {
        unset($this->persistedBlockCounts[$runId]);
        $this->projector->reset();
    }

    /**
     * Convert a TranscriptBlock to a persisted TranscriptEntry.
     *
     * Maps the block kind to a role string suitable for transcript.jsonl format.
     */
    private function blockToEntry(TranscriptBlock $block): TranscriptEntry
    {
        $role = match ($block->kind) {
            TranscriptBlockKindEnum::UserMessage => 'user',
            TranscriptBlockKindEnum::AssistantMessage => 'assistant',
            TranscriptBlockKindEnum::AssistantThinking => 'assistant',
            TranscriptBlockKindEnum::ToolCall => 'tool',
            TranscriptBlockKindEnum::ToolResult => 'tool',
            TranscriptBlockKindEnum::Error => 'error',
            TranscriptBlockKindEnum::Cancelled => 'system',
            TranscriptBlockKindEnum::System => 'system',
            TranscriptBlockKindEnum::Progress => 'system',
            TranscriptBlockKindEnum::Question => 'user',
            TranscriptBlockKindEnum::Approval => 'user',
        };

        return new TranscriptEntry(
            role: $role,
            text: $block->text,
            meta: array_merge($block->meta, [
                'block_id' => $block->id,
                'block_kind' => $block->kind->value,
                'seq' => $block->seq,
                'run_id' => $block->runId,
            ]),
        );
    }
}
