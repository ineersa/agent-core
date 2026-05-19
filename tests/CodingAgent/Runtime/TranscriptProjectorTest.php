<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptProjector::class)]
#[CoversClass(TranscriptBlock::class)]
#[CoversClass(TranscriptBlockKindEnum::class)]
final class TranscriptProjectorTest extends TestCase
{
    private TranscriptProjector $projector;

    protected function setUp(): void
    {
        $this->projector = new TranscriptProjector();
    }

    // ── Initial state ────────────────────────────────────────────────────────

    public function testInitialStateHasEmptyBlocks(): void
    {
        $this->assertSame([], $this->projector->blocks());
    }

    // ── User message ─────────────────────────────────────────────────────────

    public function testUserMessageCreatesBlock(): void
    {
        $this->projector->accept($this->event(
            'user.message_submitted',
            1,
            ['message_id' => 'msg_1', 'text' => 'Hello, world!'],
        ));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('msg_1', $blocks[0]->id);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
        $this->assertSame('Hello, world!', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame(0, $blocks[0]->seq);
    }

    public function testUserMessageEmptyText(): void
    {
        $this->projector->accept($this->event(
            'user.message_submitted',
            1,
            ['message_id' => 'm_empty'],
        ));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('', $blocks[0]->text);
    }

    // ── Assistant text stream (normal path) ──────────────────────────────────

    public function testAssistantTextStreamCreatesAndAccumulatesBlock(): void
    {
        $this->projector->accept($this->event('assistant.message_started', 1, ['message_id' => 'a1']));
        $this->projector->accept($this->event('assistant.text_started', 2, [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 3, [
            'block_id' => 'a1_t0', 'delta' => 'Hello',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 4, [
            'block_id' => 'a1_t0', 'delta' => ', ',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 5, [
            'block_id' => 'a1_t0', 'delta' => 'world!',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('a1_t0', $blocks[0]->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        $this->assertSame('Hello, world!', $blocks[0]->text);
        $this->assertTrue($blocks[0]->streaming);
        $this->assertSame('a1', $blocks[0]->meta['message_id']);
    }

    public function testAssistantTextCompletedFinalizesBlock(): void
    {
        $this->projector->accept($this->event('assistant.text_started', 1, [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 2, [
            'block_id' => 'a1_t0', 'delta' => 'Hey',
        ]));
        $this->projector->accept($this->event('assistant.text_completed', 3, [
            'block_id' => 'a1_t0', 'text' => 'Hey there',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming, 'Block should be finalized');
        $this->assertSame('Hey there', $blocks[0]->text, 'Full text from completed payload takes precedence');
    }

    // ── Assistant thinking stream ────────────────────────────────────────────

    public function testAssistantThinkingStreamCreatesAndAccumulatesBlock(): void
    {
        $this->projector->accept($this->event('assistant.thinking_started', 1, [
            'message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_th0',
        ]));
        $this->projector->accept($this->event('assistant.thinking_delta', 2, [
            'block_id' => 'a1_th0', 'delta' => 'Let me think...',
        ]));
        $this->projector->accept($this->event('assistant.thinking_delta', 3, [
            'block_id' => 'a1_th0', 'delta' => ' done.',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('a1_th0', $blocks[0]->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('Let me think... done.', $blocks[0]->text);
        $this->assertTrue($blocks[0]->streaming);
        $this->assertTrue($blocks[0]->collapsed, 'Thinking blocks are collapsed by default');
        $this->assertSame(1, $blocks[0]->meta['content_index']);
    }

    public function testAssistantThinkingCompletedFinalizesBlock(): void
    {
        $this->projector->accept($this->event('assistant.thinking_started', 1, [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0',
        ]));
        $this->projector->accept($this->event('assistant.thinking_delta', 2, [
            'block_id' => 'a1_th0', 'delta' => 'Hmm',
        ]));
        $this->projector->accept($this->event('assistant.thinking_completed', 3, [
            'block_id' => 'a1_th0', 'text' => 'Hmm, interesting.',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame('Hmm, interesting.', $blocks[0]->text);
    }

    // ── Message with text AND thinking (interleaved) ─────────────────────────

    public function testInterleavedTextAndThinkingBlocks(): void
    {
        // Scenario: assistant responds with text + thinking interleaved
        $this->projector->accept($this->event('assistant.message_started', 1, ['message_id' => 'a1']));
        $this->projector->accept($this->event('assistant.thinking_started', 2, [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0',
        ]));
        $this->projector->accept($this->event('assistant.thinking_delta', 3, [
            'block_id' => 'a1_th0', 'delta' => 'Analyzing...',
        ]));
        $this->projector->accept($this->event('assistant.thinking_completed', 4, [
            'block_id' => 'a1_th0', 'text' => 'Analyzing the request.',
        ]));
        $this->projector->accept($this->event('assistant.text_started', 5, [
            'message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 6, [
            'block_id' => 'a1_t0', 'delta' => 'The answer is 42.',
        ]));
        $this->projector->accept($this->event('assistant.text_completed', 7, [
            'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.message_completed', 8, ['message_id' => 'a1']));

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        // Thinking block first
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('Analyzing the request.', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);

        // Text block second
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[1]->kind);
        $this->assertSame('The answer is 42.', $blocks[1]->text);
        $this->assertFalse($blocks[1]->streaming);
    }

    // ── message_completed finalizes remaining streaming blocks ───────────────

    public function testMessageCompletedFinalizesStreamingBlocks(): void
    {
        // text_started but no text_completed — streaming is still true
        $this->projector->accept($this->event('assistant.text_started', 1, [
            'message_id' => 'a1', 'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 2, [
            'block_id' => 'a1_t0', 'delta' => 'Streaming...',
        ]));

        $this->assertTrue($this->projector->blocks()[0]->streaming);

        $this->projector->accept($this->event('assistant.message_completed', 3, [
            'message_id' => 'a1',
        ]));

        $this->assertFalse($this->projector->blocks()[0]->streaming);
    }

    // ── message_failed ───────────────────────────────────────────────────────

    public function testMessageFailedFinalizesStreamingAndAppendsErrorBlock(): void
    {
        $this->projector->accept($this->event('assistant.message_started', 1, ['message_id' => 'a1']));
        $this->projector->accept($this->event('assistant.text_started', 2, [
            'message_id' => 'a1', 'block_id' => 'a1_t0',
        ]));
        $this->projector->accept($this->event('assistant.text_delta', 3, [
            'block_id' => 'a1_t0', 'delta' => 'Partial text...',
        ]));
        $this->projector->accept($this->event('assistant.message_failed', 4, [
            'message_id' => 'a1', 'stop_reason' => 'provider_error', 'text' => 'API rate limit exceeded',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        // Text block should be finalized
        $this->assertSame('a1_t0', $blocks[0]->id);
        $this->assertSame('Partial text...', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);

        // Error block appended
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[1]->kind);
        $this->assertSame('API rate limit exceeded', $blocks[1]->text);
        $this->assertSame('a1', $blocks[1]->meta['message_id']);
        $this->assertSame('provider_error', $blocks[1]->meta['stop_reason']);
    }

    public function testMessageFailedWithoutStreamingBlocks(): void
    {
        $this->projector->accept($this->event('assistant.message_failed', 1, [
            'message_id' => 'a1',
            'text' => 'Timeout',
            'stop_reason' => 'timeout',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[0]->kind);
        $this->assertSame('Timeout', $blocks[0]->text);
    }

    // ── Replay determinism ───────────────────────────────────────────────────

    public function testReplayProducesSameBlocks(): void
    {
        $events = $this->sampleEvents();

        // First pass
        foreach ($events as $event) {
            $this->projector->accept($this->toEvent($event));
        }
        $first = $this->projector->blocks();

        // Reset and replay
        $this->projector->reset();
        foreach ($events as $event) {
            $this->projector->accept($this->toEvent($event));
        }
        $second = $this->projector->blocks();

        $this->assertCount(\count($first), $second);

        foreach ($first as $i => $block) {
            $this->assertSame($block->id, $second[$i]->id, "Block id at index $i");
            $this->assertSame($block->kind, $second[$i]->kind, "Block kind at index $i");
            $this->assertSame($block->text, $second[$i]->text, "Block text at index $i");
            $this->assertSame($block->streaming, $second[$i]->streaming, "Block streaming at index $i");
            $this->assertSame($block->collapsed, $second[$i]->collapsed, "Block collapsed at index $i");
            $this->assertSame($block->meta, $second[$i]->meta, "Block meta at index $i");
            $this->assertSame($block->seq, $second[$i]->seq, "Block seq at index $i");
        }
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    public function testResetClearsAllState(): void
    {
        $this->projector->accept($this->event(
            'user.message_submitted', 1, ['message_id' => 'u1', 'text' => 'Hi'],
        ));
        $this->projector->accept($this->event('assistant.text_started', 2, [
            'message_id' => 'a1', 'block_id' => 'b1',
        ]));

        $this->assertNotEmpty($this->projector->blocks());

        $this->projector->reset();

        $this->assertSame([], $this->projector->blocks());
    }

    public function testResetThenReacceptProducesSameSeq(): void
    {
        $this->projector->accept($this->event('user.message_submitted', 1, ['message_id' => 'u1', 'text' => 'A']));
        $seqAfterFirst = $this->projector->blocks()[0]->seq;

        $this->projector->reset();
        $this->projector->accept($this->event('user.message_submitted', 1, ['message_id' => 'u1', 'text' => 'A']));
        $seqAfterReset = $this->projector->blocks()[0]->seq;

        $this->assertSame($seqAfterFirst, $seqAfterReset, 'Reset must renumber blocks from 0');
    }

    // ── Unknown event types ──────────────────────────────────────────────────

    public function testUnknownEventTypeIsIgnored(): void
    {
        $this->projector->accept($this->event('tool_call.started', 1, [
            'tool_call_id' => 'tc1', 'tool_name' => 'read',
        ]));
        $this->projector->accept($this->event('run.started', 2));
        $this->projector->accept($this->event('progress.updated', 3, ['message' => 'working...']));

        $this->assertSame([], $this->projector->blocks(), 'RTVS-03 scope excludes tool/HITL/cancel/lifecycle');
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testDeltaBeforeStartIsSilentlyIgnored(): void
    {
        // Delta with no matching active block
        $this->projector->accept($this->event('assistant.text_delta', 1, [
            'block_id' => 'nonexistent', 'delta' => 'oops',
        ]));

        $this->assertSame([], $this->projector->blocks());
    }

    public function testCompletedBeforeStartIsSilentlyIgnored(): void
    {
        $this->projector->accept($this->event('assistant.text_completed', 1, [
            'block_id' => 'ghost', 'text' => 'phantom',
        ]));

        $this->assertSame([], $this->projector->blocks());
    }

    public function testMessageCompletedWithNoStreamingBlocksDoesNothing(): void
    {
        $this->projector->accept($this->event('assistant.message_completed', 1, ['message_id' => 'unknown']));

        $this->assertSame([], $this->projector->blocks());
    }

    public function testEmptyDeltaIsNoOp(): void
    {
        $this->projector->accept($this->event('assistant.text_started', 1, [
            'message_id' => 'a1', 'block_id' => 'b1',
        ]));
        $before = $this->projector->blocks()[0];

        $this->projector->accept($this->event('assistant.text_delta', 2, [
            'block_id' => 'b1', 'delta' => '',
        ]));

        $after = $this->projector->blocks()[0];
        $this->assertSame($before, $after, 'Empty delta should not change the block');
        // But appendText('') returns $this, so it's the same object.
        // Actually appendText with '' returns $this, and replaceInBlocks replaces.
        // The new block IS the old block object. So $after === $before.
    }

    public function testAssistantMetaIncludesModelAndStopReason(): void
    {
        $this->projector->accept($this->event('assistant.text_started', 1, [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'b1',
            'model' => 'claude/sonnet-4', 'stop_reason' => 'stop',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertSame('claude/sonnet-4', $blocks[0]->meta['model']);
        $this->assertSame('stop', $blocks[0]->meta['stop_reason']);
    }

    public function testSeqIsMonotonic(): void
    {
        $this->projector->accept($this->event('user.message_submitted', 1, ['message_id' => 'u1', 'text' => 'Hi']));
        $this->projector->accept($this->event('assistant.thinking_started', 2, [
            'message_id' => 'a1', 'block_id' => 'th1',
        ]));
        $this->projector->accept($this->event('assistant.text_started', 3, [
            'message_id' => 'a1', 'block_id' => 't1',
        ]));
        $this->projector->accept($this->event('assistant.message_failed', 4, ['message_id' => 'a1']));

        $blocks = $this->projector->blocks();
        $this->assertCount(4, $blocks);
        for ($i = 0; $i < \count($blocks); ++$i) {
            $this->assertSame($i, $blocks[$i]->seq, "Block at index $i should have seq=$i");
        }
    }

    public function testMultipleUserMessages(): void
    {
        $this->projector->accept($this->event('user.message_submitted', 1, ['message_id' => 'u1', 'text' => 'Q1']));
        $this->projector->accept($this->event('user.message_submitted', 2, ['message_id' => 'u2', 'text' => 'Q2']));

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('u1', $blocks[0]->id);
        $this->assertSame('u2', $blocks[1]->id);
        $this->assertSame(0, $blocks[0]->seq);
        $this->assertSame(1, $blocks[1]->seq);
    }

    public function testMessageStartedWithoutBlocksIsNoOp(): void
    {
        $this->projector->accept($this->event('assistant.message_started', 1, ['message_id' => 'a1']));

        $this->assertSame([], $this->projector->blocks(), 'message_started alone does not create blocks');
    }

    public function testThinkingBlockIsCollapsedByDefault(): void
    {
        $this->projector->accept($this->event('assistant.thinking_started', 1, [
            'message_id' => 'a1', 'block_id' => 'th1',
        ]));

        $this->assertTrue($this->projector->blocks()[0]->collapsed);
    }

    public function testTextBlockIsNotCollapsed(): void
    {
        $this->projector->accept($this->event('assistant.text_started', 1, [
            'message_id' => 'a1', 'block_id' => 't1',
        ]));

        $this->assertFalse($this->projector->blocks()[0]->collapsed);
    }

    public function testErrorBlockFromMessageFailedIsNotStreaming(): void
    {
        $this->projector->accept($this->event('assistant.message_failed', 1, [
            'message_id' => 'a1', 'text' => 'Failed',
        ]));

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertFalse($blocks[0]->collapsed);
    }

    // ── Helper: build a RuntimeEvent-shaped array ────────────────────────────

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{type: string, runId: string, seq: int, payload: array<string, mixed>, v: int}
     */
    private function event(string $type, int $seq, array $payload = [], string $runId = 'run_1'): array
    {
        return [
            'type' => $type,
            'runId' => $runId,
            'seq' => $seq,
            'payload' => $payload,
            'v' => 1,
        ];
    }

    // ── Data providers ───────────────────────────────────────────────────────

    /** @return list<array{type: string, seq: int, payload: array<string, mixed>}> */
    private function sampleEvents(): array
    {
        return [
            ['type' => 'user.message_submitted', 'seq' => 1, 'payload' => ['message_id' => 'u1', 'text' => 'Explain FP']],
            ['type' => 'assistant.message_started', 'seq' => 2, 'payload' => ['message_id' => 'a1']],
            ['type' => 'assistant.thinking_started', 'seq' => 3, 'payload' => ['message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0']],
            ['type' => 'assistant.thinking_delta', 'seq' => 4, 'payload' => ['block_id' => 'a1_th0', 'delta' => 'FP is about']],
            ['type' => 'assistant.thinking_delta', 'seq' => 5, 'payload' => ['block_id' => 'a1_th0', 'delta' => ' pure functions.']],
            ['type' => 'assistant.thinking_completed', 'seq' => 6, 'payload' => ['block_id' => 'a1_th0', 'text' => 'FP is about pure functions.']],
            ['type' => 'assistant.text_started', 'seq' => 7, 'payload' => ['message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0']],
            ['type' => 'assistant.text_delta', 'seq' => 8, 'payload' => ['block_id' => 'a1_t0', 'delta' => 'Functional']],
            ['type' => 'assistant.text_delta', 'seq' => 9, 'payload' => ['block_id' => 'a1_t0', 'delta' => ' programming']],
            ['type' => 'assistant.text_delta', 'seq' => 10, 'payload' => ['block_id' => 'a1_t0', 'delta' => ' uses immutable data.']],
            ['type' => 'assistant.text_completed', 'seq' => 11, 'payload' => ['block_id' => 'a1_t0', 'text' => 'Functional programming uses immutable data.']],
            ['type' => 'assistant.message_completed', 'seq' => 12, 'payload' => ['message_id' => 'a1']],
        ];
    }

    /**
     * Convert the compact event form into the full RuntimeEvent array shape.
     *
     * @param array{type: string, seq: int, payload: array<string, mixed>} $e
     *
     * @return array{type: string, runId: string, seq: int, payload: array<string, mixed>, v: int}
     */
    private function toEvent(array $e, string $runId = 'run_1'): array
    {
        return [
            'type' => $e['type'],
            'runId' => $runId,
            'seq' => $e['seq'],
            'payload' => $e['payload'],
            'v' => 1,
        ];
    }
}
