<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Projects compaction lifecycle events into transcript blocks.
 *
 * Contributes to {@see TranscriptProjector} via Symfony EventDispatcher.
 */
final readonly class CompactionProjectionSubscriber implements EventSubscriberInterface
{
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
                'lifecycle' => 'compaction_completed',
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
}
