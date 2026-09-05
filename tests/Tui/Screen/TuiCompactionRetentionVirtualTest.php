<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CompactionProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Compaction evicts source blocks and mounted content, not just painted rows. */
final class TuiCompactionRetentionVirtualTest extends TestCase
{
    #[Test]
    public function previousSegmentLeavesProjectionAndScreenWithoutReturningOnResizeOrReplacement(): void
    {
        $projection = new TranscriptProjectionState();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new CompactionProjectionSubscriber());
        $projector = new TranscriptProjector($dispatcher, $projection);
        $session = new TuiSessionState('retention-virtual');
        $harness = new VirtualTuiHarness(columns: 100, rows: 50, sessionId: 'retention-virtual');

        $apply = static function (RuntimeEvent $event) use ($projector, $session, $harness): void {
            $projector->accept($event);
            $changes = $projector->drainChanges();
            $session->applyTranscriptChangeSet($changes);
            // RuntimeEventPoller publishes the authoritative session snapshot on
            // retention boundaries, including eviction of session-local notices.
            if (null !== $changes->retentionFloorBlockId) {
                $harness->screen()->setTranscriptBlocks($session->transcript);
            } else {
                $harness->screen()->applyTranscriptChangeSet($changes);
            }
        };
        $user = static fn (string $id, int $seq): RuntimeEvent => new RuntimeEvent(
            'user.message_submitted', 'retention-virtual', $seq,
            ['message_id' => $id, 'text' => $id],
        );
        $complete = static fn (int $seq): RuntimeEvent => new RuntimeEvent(
            'compaction.completed', 'retention-virtual', $seq, [],
        );

        $apply($user('first-segment', 1));
        $session->appendTranscriptBlock(new TranscriptBlock(
            id: 'local-first', kind: TranscriptBlockKindEnum::Error,
            runId: 'retention-virtual', seq: 1000, text: 'first-local-notice',
        ));
        $harness->screen()->setTranscriptBlocks($session->transcript);
        $apply($complete(2));
        $apply($user('second-segment', 3));
        $before = $harness->plainScreenText();
        $this->assertStringContainsString('first-segment', $before);
        $this->assertStringContainsString('second-segment', $before);
        $this->assertStringContainsString('first-local-notice', $before);

        $apply($complete(4));
        $apply($user('third-segment', 5));
        $after = $harness->plainScreenText();
        $this->assertStringNotContainsString('first-segment', $after);
        $this->assertStringNotContainsString('first-local-notice', $after);
        $this->assertStringContainsString('second-segment', $after);
        $this->assertStringContainsString('third-segment', $after);
        $this->assertNotContains('first-segment', array_map(
            static fn (TranscriptBlock $block): string => $block->id,
            $projector->blocks(),
        ));

        $apply($complete(6));
        $harness->screen()->setTranscriptBlocks($session->transcript);
        $harness->terminal()->simulateResize(60, 50);
        $resized = $harness->plainScreenText();
        $this->assertStringNotContainsString('first-segment', $resized);
        $this->assertStringNotContainsString('second-segment', $resized);
        $this->assertStringContainsString('third-segment', $resized);

        $harness->startInputLoop();
        try {
            $harness->sendInput('retained-editor-input');
            $this->assertStringContainsString('retained-editor-input', $harness->plainScreenText());
        } finally {
            $harness->stopInputLoop();
        }
    }
}
