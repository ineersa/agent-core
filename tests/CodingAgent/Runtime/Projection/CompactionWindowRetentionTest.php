<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Rolling compaction-window retention for projected transcripts.
 *
 * Thesis: successful compaction.completed advances one owned retention decision
 * in TranscriptProjectionState. Compaction #1 keeps conversation #1;
 * compaction #2 evicts conversation #1; compaction #3 evicts conversation #2.
 * Removals drain to TuiSessionState; duplicate completion seq does not prune twice.
 */
#[CoversClass(CompactionProjectionSubscriber::class)]
#[CoversClass(TranscriptProjectionState::class)]
final class CompactionWindowRetentionTest extends TestCase
{
    private TranscriptProjector $projector;
    private TranscriptProjectionState $state;
    private int $seq = 0;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $this->state = new TranscriptProjectionState();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new CompactionProjectionSubscriber());
        $this->projector = new TranscriptProjector($dispatcher, $this->state);
        $this->seq = 0;
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
        $this->assertTrue($this->hasCompactionCompletedMarker());
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
    public function testDuplicateCompletionSeqDoesNotAdvanceWindowTwice(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');

        $completed = new RuntimeEvent(
            type: 'compaction.completed',
            runId: 'run-1',
            seq: 20,
            payload: [
                'estimated_tokens_before' => 100,
                'estimated_tokens_after' => 50,
            ],
        );
        $this->projector->accept($completed);
        $afterFirst = $this->blockIds();
        $this->assertNotContains('u1', $afterFirst);
        $this->assertContains('u2', $afterFirst);

        // Duplicate delivery of the same positive seq must be a no-op for retention.
        $this->projector->accept($completed);
        $afterDuplicate = $this->blockIds();
        $this->assertSame($afterFirst, $afterDuplicate);
        $this->assertContains('u2', $afterDuplicate);
        $this->assertSame(2, $this->countCompactionCompletedMarkers());
    }

    #[Test]
    public function testRetentionRemovalsPropagateToSessionState(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');
        $session = new TuiSessionState('session-1');
        $session->applyTranscriptChangeSet($this->state->drainChanges());
        $this->assertContains('u1', array_map(static fn (TranscriptBlock $b): string => $b->id, $session->transcript));

        $this->acceptCompactionCompleted(20);
        $delta = $this->state->drainChanges();
        $this->assertContains('u1', $delta->removals);
        $this->assertTrue($session->applyTranscriptChangeSet($delta));

        $sessionIds = array_map(static fn (TranscriptBlock $b): string => $b->id, $session->transcript);
        $this->assertNotContains('u1', $sessionIds);
        $this->assertContains('u2', $sessionIds);
    }

    #[Test]
    public function testFullSnapshotDoesNotResurrectEvictedBlocksOrDropLocalUi(): void
    {
        $this->acceptUser('u1', 'conversation 1');
        $this->acceptCompactionCompleted(10);
        $this->acceptUser('u2', 'conversation 2');
        $this->acceptCompactionCompleted(20);

        $session = new TuiSessionState('session-1');
        $session->applyTranscriptChangeSet($this->state->drainChanges());
        $local = new TranscriptBlock(
            id: 'local-error',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'run-1',
            seq: 99,
            text: 'local ui error',
        );
        $session->appendTranscriptBlock($local);

        // Simulate a later full replace from the already-pruned projector snapshot.
        $session->applyTranscriptChangeSet(\Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet::full(
            $this->projector->blocks(),
        ));

        $ids = array_map(static fn (TranscriptBlock $b): string => $b->id, $session->transcript);
        $this->assertNotContains('u1', $ids);
        $this->assertContains('u2', $ids);
        $this->assertContains('local-error', $ids);
        $this->assertSame('local-error', $ids[array_key_last($ids)]);
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

    private function hasCompactionCompletedMarker(): bool
    {
        return $this->countCompactionCompletedMarkers() > 0;
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
}
