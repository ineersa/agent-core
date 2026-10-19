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
 * reproject remains allowed for pairing/question/user changes. Existing tool
 * exchanges must stay dependency-bounded even with distant call/result sources.
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
        $this->assertNotNull($bootstrap->order);
        // History rows may insert turn separators; tail must remain the stream key.
        $this->assertGreaterThanOrEqual(41, \count($bootstrap->order));
        $this->assertSame('stream-assistant', $bootstrap->order[\count($bootstrap->order) - 1]);

        $streamed = $streaming->with(text: 'partial more tokens');
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$streamed]));

        $this->assertFalse($patch->isFull());
        $this->assertTrue($patch->isContentOnly(), 'Pure tail stream must be content-only (non-structural)');
        $this->assertFalse($patch->orderChanged);
        $this->assertNull($patch->order, 'Content-only patch must not carry a full order snapshot');
        $this->assertCount(1, $patch->upserts);
        $this->assertSame('stream-assistant', $patch->upserts[0]->key);
        $this->assertSame([], $patch->removals);
        $this->assertSame('partial more tokens', $patch->upserts[0]->primary?->text);
        $this->assertSame(TranscriptVisualPatch::MODE_INCREMENTAL, $patch->mode);
        // Unchanged order is proven by mounted identity/render tests, not a test-only accessor.
    }

    #[Test]
    public function testExistingToolUpdatesAreBoundedAndKeepBothCanonicalSources(): void
    {
        $projector = new TranscriptVisualProjector();
        $call = new TranscriptBlock('call', TranscriptBlockKindEnum::ToolCall, self::SESSION_ID, 1, 'arguments', [
            'tool_name' => 'read', 'tool_call_id' => 'distant', 'arguments' => ['path' => 'before.txt'],
        ], true);
        $history = [];
        for ($i = 0; $i < 1000; ++$i) {
            $history[] = new TranscriptBlock('history-'.$i, TranscriptBlockKindEnum::AssistantMessage, self::SESSION_ID, $i + 2, 'retained history');
        }
        $result = new TranscriptBlock('result', TranscriptBlockKindEnum::ToolResult, self::SESSION_ID, 1002, 'progress', [
            'tool_name' => 'read', 'tool_call_id' => 'distant', 'result' => 'before result',
        ]);
        $projector->replaceAll([$call, ...$history, $result]);

        $call = $call->with(text: 'latest argument delta', meta: [...$call->meta, 'arguments' => ['path' => 'after.txt']]);
        $result = $result->with(text: 'latest progress', meta: [...$result->meta, 'result' => 'after result']);
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$call, $result]));

        $this->assertTrue($patch->isContentOnly());
        $this->assertNull($patch->order, 'A stable tool update must not scan/diff the retained visual order');
        $this->assertCount(1, $patch->upserts);
        $this->assertSame('exchange:distant', $patch->upserts[0]->key);
        $this->assertSame($call, $patch->upserts[0]->primary);
        $this->assertSame($result, $patch->upserts[0]->secondary);
        $this->assertSame($call, $projector->exportBlocks()[0]);
        $this->assertSame($result, $projector->exportBlocks()[1001]);

        // A result update must not turn its exchange into a standalone card, even
        // when a simultaneous ordinary stream update expands neighboring sources.
        $stream = $history[999]->with(text: 'new assistant text');
        $result = $result->with(text: 'another progress delta');
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$stream, $result]));
        $this->assertTrue($patch->isContentOnly());
        $this->assertCount(2, $patch->upserts);
        $exchange = array_values(array_filter($patch->upserts, static fn ($node): bool => 'exchange:distant' === $node->key))[0];
        $this->assertSame($call, $exchange->primary);
        $this->assertSame($result, $exchange->secondary);
    }

    #[Test]
    public function testPairingChangesKeepFullPolicyAndMatchFreshProjection(): void
    {
        $projector = new TranscriptVisualProjector();
        $call = new TranscriptBlock('call', TranscriptBlockKindEnum::ToolCall, self::SESSION_ID, 1, 'read', [
            'tool_name' => 'read', 'tool_call_id' => 'competing',
        ]);
        $first = new TranscriptBlock('first', TranscriptBlockKindEnum::ToolResult, self::SESSION_ID, 2, 'first', [
            'tool_name' => 'read', 'tool_call_id' => 'competing',
        ]);
        $second = new TranscriptBlock('second', TranscriptBlockKindEnum::ToolResult, self::SESSION_ID, 3, 'longer second result', [
            'tool_name' => 'read', 'tool_call_id' => 'competing',
        ]);
        $projector->replaceAll([$call, $first, $second]);
        $first = $first->with(meta: [...$first->meta, 'is_error' => true]);
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$first]));
        $this->assertFalse($patch->isContentOnly(), 'Competing result scores can change pairing');
        $this->assertSame($first, $patch->upserts[0]->secondary);

        $changedCall = $call->with(meta: [...$call->meta, 'tool_name' => 'ask_human']);
        $patch = $projector->applyChangeSet(TranscriptChangeSet::incremental([$changedCall]));
        $this->assertFalse($patch->isContentOnly(), 'Suppression and tool identity changes remain structural');
        $fresh = new TranscriptVisualProjector()->replaceAll($projector->exportBlocks());
        $snapshot = new \ReflectionProperty($projector, 'nodesByKey')->getValue($projector);
        $this->assertEquals($fresh->nodes, array_values($snapshot));

        // Full replacement remains the explicit history/reorder path, and a result
        // arriving later is paired by the structural policy rather than this fast path.
        $reordered = $projector->replaceAll([$second, $call, $first]);
        $this->assertTrue($reordered->isFull());
        $projector->replaceAll([$call]);
        $late = $projector->applyChangeSet(TranscriptChangeSet::incremental([$second]));
        $this->assertFalse($late->isContentOnly());
        $this->assertSame($second, $late->upserts[0]->secondary);
        $removed = $projector->applyChangeSet(TranscriptChangeSet::incremental([], ['second']));
        $this->assertFalse($removed->isContentOnly());
        $this->assertNull($removed->upserts[0]->secondary);
    }
}
