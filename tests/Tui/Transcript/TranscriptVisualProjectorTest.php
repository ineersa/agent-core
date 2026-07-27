<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptChangeSet;
use Ineersa\Tui\Transcript\TranscriptVisualPatch;
use Ineersa\Tui\Transcript\TranscriptVisualProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Direct production-contract proof for {@see TranscriptVisualProjector} patches.
 *
 * Test thesis: ordinary pure tail stream must emit a content-only visual patch
 * (no order payload, only the streaming key touched). Structural full-history
 * reproject remains allowed for tool/question/user events but is not this path.
 */
final class TranscriptVisualProjectorTest extends TestCase
{
    private const string SESSION_ID = 'visual-projector-contract';

    #[Test]
    public function testPureTailStreamEmitsContentOnlyPatchWithoutOrderSnapshot(): void
    {
        $projector = new TranscriptVisualProjector();

        $history = [];
        for ($i = 0; $i < 40; ++$i) {
            $history[] = new TranscriptBlock(
                id: 'hist-'.$i,
                kind: TranscriptBlockKindEnum::UserMessage,
                runId: self::SESSION_ID,
                seq: $i + 1,
                text: 'finalized history row '.$i,
            );
        }

        $streaming = new TranscriptBlock(
            id: 'stream-assistant',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: self::SESSION_ID,
            seq: 100,
            text: 'partial',
            streaming: true,
        );

        $bootstrap = $projector->replaceAll([...$history, $streaming]);
        $this->assertTrue($bootstrap->isFull());
        $orderBefore = $projector->currentOrder();
        // History rows may insert turn separators; tail must remain the stream key.
        $this->assertGreaterThanOrEqual(41, \count($orderBefore));
        $this->assertSame('stream-assistant', $orderBefore[\count($orderBefore) - 1]);

        $streamed = $streaming->with(text: 'partial more tokens');
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$streamed]));

        $this->assertFalse($patch->isFull());
        $this->assertTrue($patch->isContentOnly(), 'Pure tail stream must be content-only (non-structural)');
        $this->assertFalse($patch->orderChanged);
        $this->assertNull($patch->order, 'Content-only patch must not carry a full order snapshot');
        $this->assertSame(['stream-assistant'], $patch->touchedKeys());
        $this->assertCount(1, $patch->upserts);
        $this->assertSame([], $patch->removals);
        $this->assertSame('partial more tokens', $patch->upserts[0]->primary?->text);

        $orderAfter = $projector->currentOrder();
        $this->assertSame($orderBefore, $orderAfter, 'Retained visual order must stay identical on content-only stream');
        $this->assertSame(TranscriptVisualPatch::MODE_INCREMENTAL, $patch->mode);
    }
}
