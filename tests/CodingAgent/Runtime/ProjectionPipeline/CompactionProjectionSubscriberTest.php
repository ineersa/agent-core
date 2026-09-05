<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjectionEvent;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Compaction lifecycle projection + rolling retention window.
 *
 * Thesis: compaction.completed text stays glyph-free with token estimates in meta;
 * successful completion advances one owned retention decision in
 * TranscriptProjectionState. Compaction #1 keeps conversation #1; #2 evicts #1;
 * #3 evicts #2. Failure never prunes. Duplicate positive seq is a no-op.
 */
#[CoversClass(CompactionProjectionSubscriber::class)]
#[CoversClass(TranscriptProjectionState::class)]
final class CompactionProjectionSubscriberTest extends TestCase
{
    private CompactionProjectionSubscriber $subscriber;
    private TranscriptProjectionState $state;
    private TranscriptProjector $projector;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->subscriber = new CompactionProjectionSubscriber();
        $this->state = new TranscriptProjectionState();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber($this->subscriber);
        $this->projector = new TranscriptProjector($dispatcher, $this->state);
        $this->seq = 0;
    }

    /**
     * Subject: compaction.completed block text never includes token estimates.
     *
     * The internal CompactionTokenEstimator uses a text-only heuristic that
     * excludes tool definitions / JSON envelope, producing token counts
     * that differ from the provider input_tokens used for auto-trigger
     * thresholds.  Displaying both estimates side-by-side is confusing.
     *
     * Projection text is glyph-free ('Conversation compacted.'); the TUI renderer
     * owns the ⧉ lifecycle prefix via meta.lifecycle — no Token estimate: banner.
     */
    public function testCompactionStartedTextIsGlyphFree(): void
    {
        $event = new TranscriptProjectionEvent(
            runtimeEvent: new RuntimeEvent(
                type: 'compaction.started',
                runId: 'run-1',
                seq: $this->state->nextSeq(),
                payload: [],
            ),
            state: $this->state,
        );

        $this->subscriber->onCompactionStarted($event);

        $blocks = $this->state->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('Compacting conversation', $blocks[0]->text);
        $this->assertStringNotContainsString('◐', $blocks[0]->text);
        $this->assertSame('compaction_started', $blocks[0]->meta['lifecycle'] ?? null);
    }

    public function testCompactionCompletedTextIsGlyphFree(): void
    {
        $event = $this->makeCompactionCompletedEvent(100, 50);

        $this->subscriber->onCompactionCompleted($event);

        $block = $this->state->blocks()[0];
        $this->assertSame('Conversation compacted.', $block->text);
        $this->assertStringNotContainsString('⧉', $block->text);
    }

    public function testCompactedTextNeverShowsTokenEstimate(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: 12708,
            estimatedTokensAfter: 7255,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        $this->assertCount(1, $blocks, 'Expected one transcript block.');

        $block = $blocks[0];
        $this->assertSame(
            TranscriptBlockKindEnum::System,
            $block->kind,
            'Compaction completed block should be System kind.',
        );
        $this->assertStringNotContainsString(
            'Token estimate',
            $block->text,
            'User-visible text must not include token estimates.',
        );
        $this->assertStringContainsString(
            'Conversation compacted',
            $block->text,
            'Text should contain the compressed-intro message.',
        );
    }

    /**
     * Subject: structured metadata survives the UX change.
     *
     * The estimated_tokens_before / estimated_tokens_after fields remain
     * in the block meta for diagnostics, tests, and downstream consumers.
     */
    public function testCompactedMetaCarriesTokenEstimates(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: 12708,
            estimatedTokensAfter: 7255,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        $this->assertCount(1, $blocks);

        $block = $blocks[0];
        $meta = $block->meta;

        $this->assertArrayHasKey('estimated_tokens_before', $meta);
        $this->assertSame(12708, $meta['estimated_tokens_before']);
        $this->assertArrayHasKey('estimated_tokens_after', $meta);
        $this->assertSame(7255, $meta['estimated_tokens_after']);
    }

    /**
     * Subject: compaction completed block is produced even when
     * estimates are absent (graceful degradation).
     */
    public function testCompactedWorksWithoutEstimates(): void
    {
        $event = $this->makeCompactionCompletedEvent(
            estimatedTokensBefore: null,
            estimatedTokensAfter: null,
        );

        $this->subscriber->onCompactionCompleted($event);

        $blocks = $this->state->blocks();
        $this->assertCount(1, $blocks);
        $this->assertStringContainsString(
            'Conversation compacted',
            $blocks[0]->text,
        );
        $this->assertNull($blocks[0]->meta['estimated_tokens_before']);
        $this->assertNull($blocks[0]->meta['estimated_tokens_after']);
    }

    #[Test]
    public function testFirstCompactionKeepsPriorConversation(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);

        $ids = $this->blockIds();
        $this->assertSame(['u1'], array_values(array_filter(
            $ids,
            static fn (string $id): bool => str_starts_with($id, 'u'),
        )));
        $this->assertSame(1, $this->countCompactionCompletedMarkers());
        $this->assertCount(2, $ids);
    }

    #[Test]
    public function testSecondCompactionEvictsConversationOne(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');
        $this->acceptCompactionCompleted(20);

        $ids = $this->blockIds();
        $this->assertNotContains('u1', $ids);
        $this->assertContains('u2', $ids);
        $this->assertSame(2, $this->countCompactionCompletedMarkers());
        $this->assertSame(['u2'], array_values(array_filter(
            $ids,
            static fn (string $id): bool => str_starts_with($id, 'u'),
        )));
    }

    #[Test]
    public function testThirdCompactionEvictsConversationTwo(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');
        $this->acceptCompactionCompleted(20);
        $this->acceptUser('u3', 'conversation 3');
        $this->acceptCompactionCompleted(30);

        $ids = $this->blockIds();
        $this->assertNotContains('u1', $ids);
        $this->assertNotContains('u2', $ids);
        $this->assertContains('u3', $ids);
        $this->assertSame(2, $this->countCompactionCompletedMarkers());
    }

    #[Test]
    public function testCompactionFailedDoesNotPrune(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');

        $this->projector->accept(new RuntimeEvent(
            type: 'compaction.failed',
            runId: 'run-1',
            seq: 15,
            payload: [
                'reason' => 'empty_summary',
                'error' => 'Compaction failed: empty summary.',
            ],
        ));

        $ids = $this->blockIds();
        $this->assertContains('u1', $ids);
        $this->assertContains('u2', $ids);
        $this->assertSame(1, $this->countCompactionCompletedMarkers());
        $this->assertTrue(
            array_any(
                $this->projector->blocks(),
                static fn (TranscriptBlock $block): bool => TranscriptBlockKindEnum::Error === $block->kind,
            ),
        );
    }

    #[Test]
    public function testDuplicateCompletionSeqDoesNotAdvanceWindowTwice(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');

        $completed = $this->compactionEvent(20);
        $this->projector->accept($completed);
        $afterFirst = $this->blockIds();
        $this->assertNotContains('u1', $afterFirst);
        $this->assertContains('u2', $afterFirst);

        $this->projector->accept($completed);
        $afterDuplicate = $this->blockIds();
        $this->assertSame($afterFirst, $afterDuplicate);
        $this->assertContains('u2', $afterDuplicate);
        $this->assertSame(2, $this->countCompactionCompletedMarkers());
    }

    #[Test]
    public function testToolCallBeforeFloorKeptWhenRetainedResultReferencesIt(): void
    {
        $call = new TranscriptBlock(
            id: 'call-1',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'run-1',
            seq: $this->state->nextSeq(),
            text: 'bash',
            meta: ['tool_call_id' => 'tc-1'],
        );
        $this->state->addBlock($call);
        $this->acceptCompactionCompleted(10);

        $result = new TranscriptBlock(
            id: 'result-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run-1',
            seq: $this->state->nextSeq(),
            text: 'ok',
            meta: ['tool_call_id' => 'tc-1'],
        );
        $this->state->addBlock($result);
        $this->acceptUser('u2', 'after first compaction');
        $this->acceptCompactionCompleted(20);

        $ids = $this->blockIds();
        $this->assertContains('call-1', $ids, 'ToolCall before floor must survive when a retained result needs it');
        $this->assertContains('result-1', $ids);
        $this->assertContains('u2', $ids);
    }

    #[Test]
    public function testRetentionDrainEmitsFloorAndRemovals(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $firstCompletedId = null;
        foreach ($this->projector->blocks() as $block) {
            if ('compaction_completed' === ($block->meta['lifecycle'] ?? null)) {
                $firstCompletedId = $block->id;
            }
        }
        $this->assertNotNull($firstCompletedId);
        $this->state->drainChanges();

        $this->acceptUser('u2', 'conversation 2');
        $this->acceptCompactionCompleted(20);
        $delta = $this->state->drainChanges();

        $this->assertFalse($delta->isFull());
        $this->assertContains('u1', $delta->removals);
        $this->assertSame($firstCompletedId, $delta->retentionFloorBlockId);
        $this->assertNotContains('u1', $this->blockIds());
        $this->assertContains('u2', $this->blockIds());
    }

    #[Test]
    public function testReplaySameEventsMatchesLiveRetention(): void
    {
        $liveEvents = [
            $this->userEvent('u1', 'conversation 1', 1),
            $this->compactionEvent(10),
            $this->userEvent('u2', 'conversation 2', 11),
            $this->compactionEvent(20),
            $this->userEvent('u3', 'conversation 3', 21),
            $this->compactionEvent(30),
        ];

        foreach ($liveEvents as $event) {
            $this->projector->accept($event);
        }
        $liveIds = $this->blockIds();

        $this->projector->reset();
        foreach ($liveEvents as $event) {
            $this->projector->accept($event);
        }
        $replayIds = $this->blockIds();

        $this->assertSame($liveIds, $replayIds);
        $this->assertNotContains('u1', $replayIds);
        $this->assertNotContains('u2', $replayIds);
        $this->assertContains('u3', $replayIds);
    }

    /**
     * @return list<string>
     */
    private function blockIds(): array
    {
        return array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $this->projector->blocks(),
        );
    }

    private function countCompactionCompletedMarkers(): int
    {
        $count = 0;
        foreach ($this->projector->blocks() as $block) {
            if ('compaction_completed' === ($block->meta['lifecycle'] ?? null)) {
                ++$count;
            }
        }

        return $count;
    }

    private function acceptUser(string $messageId, string $text): void
    {
        $this->projector->accept($this->userEvent($messageId, $text, ++$this->seq));
    }

    private function acceptCompactionCompleted(int $eventSeq): void
    {
        $this->seq = max($this->seq, $eventSeq);
        $this->projector->accept($this->compactionEvent($eventSeq));
    }

    private function userEvent(string $messageId, string $text, int $seq): RuntimeEvent
    {
        return new RuntimeEvent(
            type: 'user.message_submitted',
            runId: 'run-1',
            seq: $seq,
            payload: [
                'message_id' => $messageId,
                'text' => $text,
            ],
        );
    }

    private function compactionEvent(int $seq): RuntimeEvent
    {
        return new RuntimeEvent(
            type: 'compaction.completed',
            runId: 'run-1',
            seq: $seq,
            payload: [
                'estimated_tokens_before' => 100,
                'estimated_tokens_after' => 50,
            ],
        );
    }

    private function makeCompactionCompletedEvent(
        ?int $estimatedTokensBefore,
        ?int $estimatedTokensAfter,
    ): TranscriptProjectionEvent {
        return new TranscriptProjectionEvent(
            runtimeEvent: new RuntimeEvent(
                type: 'compaction.completed',
                runId: 'run-1',
                seq: $this->state->nextSeq(),
                payload: [
                    'estimated_tokens_before' => $estimatedTokensBefore,
                    'estimated_tokens_after' => $estimatedTokensAfter,
                    'messages_before' => 10,
                    'messages_after' => 5,
                ],
            ),
            state: $this->state,
        );
    }
}
