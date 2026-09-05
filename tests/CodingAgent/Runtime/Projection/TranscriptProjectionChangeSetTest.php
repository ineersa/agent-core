<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Contract: projector dirty tracking + session application.
 *
 * Thesis: ordinary accept() batches emit bounded incremental upserts/removals
 * (not full-history materialization), and projector removals reach TuiSessionState.
 */
final class TranscriptProjectionChangeSetTest extends TestCase
{
    #[Test]
    public function testDrainChangesIsBoundedToDirtyUpsertsAndRemovals(): void
    {
        $state = new TranscriptProjectionState();
        $history = new TranscriptBlock(
            id: 'user-1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'hello',
        );
        $state->addBlock($history);
        $this->assertFalse($state->drainChanges()->isEmpty()); // first batch dirty

        $assistant = new TranscriptBlock(
            id: 'assistant-1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'partial',
            streaming: true,
        );
        $state->addBlock($assistant);
        $delta = $state->drainChanges();
        $this->assertFalse($delta->isFull());
        $this->assertCount(1, $delta->upserts, 'Only the newly dirtied block is drained');
        $this->assertSame($assistant, $delta->upserts[0]);
        $this->assertSame([], $delta->removals);
        $this->assertTrue($state->drainChanges()->isEmpty(), 'Second drain with no mutations is empty');

        $updated = $assistant->with(text: 'partial more');
        $state->updateBlock($assistant->id, $updated);
        $streamDelta = $state->drainChanges();
        $this->assertCount(1, $streamDelta->upserts);
        $this->assertSame($updated, $streamDelta->upserts[0]);
        $this->assertNotSame($history, $streamDelta->upserts[0]);
        $this->assertCount(2, $state->blocks(), 'History remains in ordered snapshot');

        // Drain complexity contract: only dirtied IDs are emitted even with large history.
        for ($i = 0; $i < 50; ++$i) {
            $state->addBlock(new TranscriptBlock(
                id: 'hist-'.$i,
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: $state->nextSeq(),
                text: 'h'.$i,
            ));
        }
        $bulk = $state->drainChanges();
        $this->assertCount(50, $bulk->upserts, 'Drain emits only newly dirtied blocks, not prior history');

        $tail = new TranscriptBlock(
            id: 'tail-only',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'x',
            streaming: true,
        );
        $state->addBlock($tail);
        $tailDelta = $state->drainChanges();
        $this->assertCount(1, $tailDelta->upserts);
        $this->assertSame('tail-only', $tailDelta->upserts[0]->id);
    }

    #[Test]
    public function testRemovalsPropagateToSessionState(): void
    {
        $projection = new TranscriptProjectionState();
        $keep = new TranscriptBlock(
            id: 'keep',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run-1',
            seq: $projection->nextSeq(),
            text: 'keep me',
        );
        $drop = new TranscriptBlock(
            id: 'drop',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $projection->nextSeq(),
            text: 'streaming phantom',
            streaming: true,
        );
        $projection->addBlock($keep);
        $projection->addBlock($drop);
        $session = new TuiSessionState('session-1');
        $session->applyTranscriptChangeSet($projection->drainChanges());
        $this->assertCount(2, $session->transcript);

        $projection->removeBlock('drop');
        $delta = $projection->drainChanges();
        $this->assertSame(['drop'], $delta->removals);
        $this->assertSame([], $delta->upserts);

        $this->assertTrue($session->applyTranscriptChangeSet($delta));
        $this->assertCount(1, $session->transcript);
        $this->assertSame('keep', $session->transcript[0]->id);
        $this->assertSame($keep, $session->transcript[0], 'Unchanged object identity preserved');
    }

    #[Test]
    public function testFullReplacementIsExplicit(): void
    {
        $session = new TuiSessionState('session-1');
        $session->appendTranscriptBlock(new TranscriptBlock(
            id: 'old',
            kind: TranscriptBlockKindEnum::System,
            runId: 'run-1',
            seq: 1,
            text: 'stale',
        ));

        $fresh = [
            new TranscriptBlock(
                id: 'new',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: 2,
                text: 'leaf',
            ),
        ];
        $this->assertTrue($session->applyTranscriptChangeSet(TranscriptChangeSet::full($fresh)));
        $this->assertSame($fresh, $session->transcript);
    }

    #[Test]
    public function testAddRemoveReAddSameIdDrainsSingleReplacementUpsert(): void
    {
        // Thesis: add → remove → re-add same ID before drain must not emit
        // duplicate upserts or a stale removal; only the replacement object.
        $state = new TranscriptProjectionState();
        $first = new TranscriptBlock(
            id: 'reuse-id',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'first body',
            streaming: true,
        );
        $state->addBlock($first);
        $state->removeBlock('reuse-id');
        $replacement = new TranscriptBlock(
            id: 'reuse-id',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'replacement body',
            streaming: true,
        );
        $state->addBlock($replacement);

        $delta = $state->drainChanges();
        $this->assertFalse($delta->isFull());
        $this->assertCount(1, $delta->upserts, 'Duplicate dirtyOrder entries must collapse to one upsert');
        $this->assertSame($replacement, $delta->upserts[0]);
        $this->assertSame([], $delta->removals, 'Re-add clears removal; drain must not emit remove+upsert');
        $this->assertCount(1, $state->blocks());
        $this->assertSame($replacement, $state->blocks()[0]);
    }

    #[Test]
    public function testBulkPruneBeforeFloorIsLinearAndEmitsRemovals(): void
    {
        $state = new TranscriptProjectionState();
        for ($i = 0; $i < 20; ++$i) {
            $state->addBlock(new TranscriptBlock(
                id: 'old-'.$i,
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: $state->nextSeq(),
                text: 'old '.$i,
            ));
        }
        $floor = new TranscriptBlock(
            id: 'floor',
            kind: TranscriptBlockKindEnum::System,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'Conversation compacted.',
            meta: ['lifecycle' => 'compaction_completed'],
        );
        $state->addBlock($floor);
        $state->addBlock(new TranscriptBlock(
            id: 'keep',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'keep',
        ));
        $state->drainChanges();

        $this->assertTrue($state->advanceCompactionRetention(5, 'floor'));
        $delta = $state->drainChanges();
        $this->assertSame('floor', $delta->retentionFloorBlockId);
        $this->assertCount(20, $delta->removals);
        $this->assertSame(['floor', 'keep'], array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $state->blocks(),
        ));
    }

    #[Test]
    public function testFullReplacementClearsWithoutPreservingLocalErrors(): void
    {
        $session = new TuiSessionState('session-1');
        $session->appendTranscriptBlock(new TranscriptBlock(
            id: 'local-error',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'run-1',
            seq: 1,
            text: 'local',
        ));
        $fresh = [
            new TranscriptBlock(
                id: 'new',
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: 'run-1',
                seq: 2,
                text: 'leaf',
            ),
        ];
        $this->assertTrue($session->applyTranscriptChangeSet(TranscriptChangeSet::full($fresh)));
        $this->assertSame(['new'], array_map(static fn (TranscriptBlock $b): string => $b->id, $session->transcript));
    }
}
