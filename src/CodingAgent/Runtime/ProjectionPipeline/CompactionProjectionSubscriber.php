<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects compaction lifecycle events into transcript blocks.
 *
 * On successful compaction.completed, also advances the rolling compaction
 * retention window owned by {@see TranscriptProjectionState}: keep the previous
 * completed conversation segment plus the current segment. Compaction #1 keeps
 * conversation #1; compaction #2 evicts conversation #1; compaction #3 evicts
 * conversation #2. Duplicate completion delivery for the same positive event
 * seq does not advance the window twice.
 *
 * Contributes to {@see TranscriptProjector} via Symfony EventDispatcher.
 */
final readonly class CompactionProjectionSubscriber implements EventSubscriberInterface
{
    private const string LIFECYCLE_COMPACTION_COMPLETED = 'compaction_completed';

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeEventTypeEnum::CompactionStarted->value => 'onCompactionStarted',
            RuntimeEventTypeEnum::CompactionCompleted->value => 'onCompactionCompleted',
            RuntimeEventTypeEnum::CompactionFailed->value => 'onCompactionFailed',
        ];
    }

    public function onCompactionStarted(TranscriptProjectionEvent $event): void
    {
        $state = $event->state;
        $runId = $event->runId();

        $state->addBlock(new TranscriptBlock(
            id: 'compaction_started_'.$state->nextSeq(),
            kind: TranscriptBlockKindEnum::System,
            runId: $runId,
            seq: $state->nextSeq(),
            text: 'Compacting conversation',
            meta: [
                'category' => 'lifecycle',
                'lifecycle' => 'compaction_started',
                'severity' => 'info',
            ],
            streaming: true,
        ));
    }

    public function onCompactionCompleted(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $runId = $event->runId();
        $eventSeq = $event->runtimeEvent->seq;

        $previousCompletedId = $this->findLatestCompactionCompletedBlockId($state);

        // Duplicate positive-seq delivery is a pure no-op: do not prune again and
        // do not append another completed marker.
        if (!$state->advanceCompactionRetention($eventSeq, $previousCompletedId)) {
            return;
        }

        // Remove the "Compacting conversation..." streaming placeholder
        // (blocks with streaming=true for this runId).
        $state->removeActiveStreamingBlocks($runId);

        $before = $p['estimated_tokens_before'] ?? null;
        $after = $p['estimated_tokens_after'] ?? null;
        $text = 'Conversation compacted.';

        $state->addBlock(new TranscriptBlock(
            id: 'compaction_completed_'.$state->nextSeq(),
            kind: TranscriptBlockKindEnum::System,
            runId: $runId,
            seq: $state->nextSeq(),
            text: $text,
            meta: [
                'category' => 'lifecycle',
                'lifecycle' => self::LIFECYCLE_COMPACTION_COMPLETED,
                'severity' => 'info',
                'estimated_tokens_before' => $before,
                'estimated_tokens_after' => $after,
                'messages_before' => $p['messages_before'] ?? null,
                'messages_after' => $p['messages_after'] ?? null,
            ],
        ));
    }

    public function onCompactionFailed(TranscriptProjectionEvent $event): void
    {
        $p = $event->payload();
        $state = $event->state;
        $runId = $event->runId();

        // Remove the "Compacting conversation..." streaming placeholder.
        $state->removeActiveStreamingBlocks($runId);

        $error = (string) ($p['error'] ?? $p['reason'] ?? 'Compaction failed.');

        $state->addBlock(new TranscriptBlock(
            id: 'compaction_failed_'.$state->nextSeq(),
            kind: TranscriptBlockKindEnum::Error,
            runId: $runId,
            seq: $state->nextSeq(),
            text: $error,
            meta: [
                'reason' => (string) ($p['reason'] ?? ''),
            ],
        ));
    }

    private function findLatestCompactionCompletedBlockId(TranscriptProjectionState $state): ?string
    {
        $latestId = null;
        foreach ($state->blocks() as $block) {
            if (TranscriptBlockKindEnum::System !== $block->kind) {
                continue;
            }
            if (self::LIFECYCLE_COMPACTION_COMPLETED !== ($block->meta['lifecycle'] ?? null)) {
                continue;
            }
            $latestId = $block->id;
        }

        return $latestId;
    }
}
