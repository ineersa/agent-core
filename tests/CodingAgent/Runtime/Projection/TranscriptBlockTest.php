<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\MissingConstructorArgumentsException;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

#[CoversClass(TranscriptBlock::class)]
#[CoversClass(TranscriptBlockKindEnum::class)]
final class TranscriptBlockTest extends TestCase
{
    private SerializerInterface $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer(
            [new BackedEnumNormalizer(), new ObjectNormalizer()],
            [],
        );
    }

    // ── Construction ────────────────────────────────────────────────────────

    public function testConstructWithMinimalFields(): void
    {
        $block = new TranscriptBlock(
            id: 'msg_1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run_abc',
            seq: 1,
        );

        $this->assertSame('msg_1', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $block->kind);
        $this->assertSame('run_abc', $block->runId);
        $this->assertSame(1, $block->seq);
        $this->assertSame('', $block->text);
        $this->assertSame([], $block->meta);
        $this->assertFalse($block->streaming);
        $this->assertFalse($block->collapsed);
    }

    public function testConstructWithAllFields(): void
    {
        $block = new TranscriptBlock(
            id: 'tool_1',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'run_xyz',
            seq: 5,
            text: 'bash: ls -la',
            meta: ['tool_name' => 'bash', 'tool_call_id' => 'call_42'],
            streaming: true,
            collapsed: false,
        );

        $this->assertSame('tool_1', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        $this->assertSame('run_xyz', $block->runId);
        $this->assertSame(5, $block->seq);
        $this->assertSame('bash: ls -la', $block->text);
        $this->assertSame(['tool_name' => 'bash', 'tool_call_id' => 'call_42'], $block->meta);
        $this->assertTrue($block->streaming);
        $this->assertFalse($block->collapsed);
    }

    /**
     * @return array<string, array{0: TranscriptBlockKindEnum}>
     */
    public static function allBlockKinds(): array
    {
        return [
            'user_message' => [TranscriptBlockKindEnum::UserMessage],
            'assistant_message' => [TranscriptBlockKindEnum::AssistantMessage],
            'assistant_thinking' => [TranscriptBlockKindEnum::AssistantThinking],
            'tool_call' => [TranscriptBlockKindEnum::ToolCall],
            'tool_result' => [TranscriptBlockKindEnum::ToolResult],
            'progress' => [TranscriptBlockKindEnum::Progress],
            'question' => [TranscriptBlockKindEnum::Question],
            'approval' => [TranscriptBlockKindEnum::Approval],
            'cancelled' => [TranscriptBlockKindEnum::Cancelled],
            'error' => [TranscriptBlockKindEnum::Error],
            'system' => [TranscriptBlockKindEnum::System],
        ];
    }

    #[DataProvider('allBlockKinds')]
    public function testConstructAllBlockKinds(TranscriptBlockKindEnum $kind): void
    {
        $block = new TranscriptBlock(
            id: 'b1',
            kind: $kind,
            runId: 'run_test',
            seq: 1,
            text: 'text for '.$kind->value,
        );

        $this->assertSame($kind, $block->kind);
        $this->assertSame('text for '.$kind->value, $block->text);
    }

    // ── TranscriptBlockKindEnum values ─────────────────────────────────────

    public function testEnumValues(): void
    {
        $expected = [
            'user_message',
            'user_message_queued',
            'assistant_message',
            'assistant_thinking',
            'tool_call',
            'tool_result',
            'progress',
            'question',
            'approval',
            'cancelled',
            'error',
            'system',
        ];

        $actual = array_map(
            static fn (TranscriptBlockKindEnum $k): string => $k->value,
            TranscriptBlockKindEnum::cases(),
        );

        $this->assertSame($expected, $actual);
    }

    public function testEnumFromString(): void
    {
        $this->assertSame(
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::from('assistant_message'),
        );
    }

    public function testEnumTryFromInvalid(): void
    {
        $this->assertNull(TranscriptBlockKindEnum::tryFrom('invalid_kind'));
    }

    // ── Symfony Serializer round-trip ───────────────────────────────────────

    public function testNormalizeProducesCorrectArray(): void
    {
        $block = new TranscriptBlock(
            id: 'msg_2',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_a',
            seq: 3,
            text: 'Hello, world!',
            meta: ['model' => 'claude-3'],
            streaming: false,
            collapsed: false,
        );

        /** @var array<string, mixed> $arr */
        $arr = $this->serializer->normalize($block);

        $this->assertSame('msg_2', $arr['id']);
        $this->assertSame('assistant_message', $arr['kind']);
        $this->assertSame('run_a', $arr['runId']);
        $this->assertSame(3, $arr['seq']);
        $this->assertSame('Hello, world!', $arr['text']);
        $this->assertSame(['model' => 'claude-3'], $arr['meta']);
        $this->assertFalse($arr['streaming']);
        $this->assertFalse($arr['collapsed']);
    }

    public function testDenormalizeReconstructsBlock(): void
    {
        $data = [
            'id' => 'msg_3',
            'kind' => 'assistant_thinking',
            'runId' => 'run_b',
            'seq' => 7,
            'text' => 'Let me think about this...',
            'meta' => ['reasoning' => 'high'],
            'streaming' => true,
            'collapsed' => false,
        ];

        $block = $this->serializer->denormalize($data, TranscriptBlock::class);

        $this->assertInstanceOf(TranscriptBlock::class, $block);
        $this->assertSame('msg_3', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $block->kind);
        $this->assertSame('run_b', $block->runId);
        $this->assertSame(7, $block->seq);
        $this->assertSame('Let me think about this...', $block->text);
        $this->assertSame(['reasoning' => 'high'], $block->meta);
        $this->assertTrue($block->streaming);
        $this->assertFalse($block->collapsed);
    }

    public function testRoundtripPreservesAllData(): void
    {
        $original = new TranscriptBlock(
            id: 'roundtrip_1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run_rt',
            seq: 42,
            text: "total 0\ndrwxr-xr-x",
            meta: [
                'tool_name' => 'bash',
                'tool_call_id' => 'call_99',
                'duration_ms' => 123,
                'is_error' => false,
            ],
            streaming: false,
            collapsed: true,
        );

        $normalized = $this->serializer->normalize($original);
        $this->assertIsArray($normalized);
        $reconstructed = $this->serializer->denormalize($normalized, TranscriptBlock::class);

        $this->assertInstanceOf(TranscriptBlock::class, $reconstructed);
        $this->assertSame($original->id, $reconstructed->id);
        $this->assertSame($original->kind, $reconstructed->kind);
        $this->assertSame($original->runId, $reconstructed->runId);
        $this->assertSame($original->seq, $reconstructed->seq);
        $this->assertSame($original->text, $reconstructed->text);
        $this->assertSame($original->meta, $reconstructed->meta);
        $this->assertSame($original->streaming, $reconstructed->streaming);
        $this->assertSame($original->collapsed, $reconstructed->collapsed);
    }

    public function testDenormalizeMissingRequiredFieldsThrows(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->serializer->denormalize([], TranscriptBlock::class);
    }

    public function testNormalizeDenormalizeAllEnumKinds(): void
    {
        foreach (TranscriptBlockKindEnum::cases() as $kind) {
            $original = new TranscriptBlock(
                id: 'b_'.$kind->value,
                kind: $kind,
                runId: 'run_serial',
                seq: 1,
                text: 'block of kind '.$kind->value,
            );

            $normalized = $this->serializer->normalize($original);
            $this->assertIsArray($normalized);

            $restored = $this->serializer->denormalize($normalized, TranscriptBlock::class);
            $this->assertInstanceOf(TranscriptBlock::class, $restored);
            $this->assertSame($kind, $restored->kind);
            $this->assertSame($original->text, $restored->text);
        }
    }

    // ── Streaming state transitions ────────────────────────────────────────

    public function testStreamingDefaultsToFalse(): void
    {
        $block = new TranscriptBlock(
            id: 's1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_s',
            seq: 1,
        );

        $this->assertFalse($block->streaming);
    }

    public function testWithChangesTextImmutably(): void
    {
        $original = new TranscriptBlock(
            id: 's2',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_s',
            seq: 2,
            text: '',
            streaming: true,
        );

        $updated = $original->with(text: 'Hello');

        // Original is unchanged
        $this->assertSame('', $original->text);
        $this->assertTrue($original->streaming);

        // New block has updated text
        $this->assertSame('Hello', $updated->text);
        $this->assertSame('s2', $updated->id);
        $this->assertSame($original->kind, $updated->kind);
        $this->assertSame($original->runId, $updated->runId);
        $this->assertSame($original->seq, $updated->seq);
        $this->assertSame($original->meta, $updated->meta);
        $this->assertSame($original->streaming, $updated->streaming);
        $this->assertSame($original->collapsed, $updated->collapsed);
    }

    public function testFinalizeSetsStreamingFalse(): void
    {
        $streamingBlock = new TranscriptBlock(
            id: 's3',
            kind: TranscriptBlockKindEnum::AssistantThinking,
            runId: 'run_s',
            seq: 3,
            text: 'partial thinking...',
            streaming: true,
        );

        $finalized = $streamingBlock->finalize();

        $this->assertTrue($streamingBlock->streaming, 'Original should still be streaming');
        $this->assertFalse($finalized->streaming, 'Finalized should not be streaming');
        $this->assertSame('partial thinking...', $finalized->text);
        $this->assertSame($streamingBlock->id, $finalized->id);
        $this->assertSame($streamingBlock->kind, $finalized->kind);
    }

    public function testAppendTextAccumulatesDeltas(): void
    {
        $block = new TranscriptBlock(
            id: 's4',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_s',
            seq: 4,
            text: 'Hello',
            streaming: true,
        );

        $block = $block->appendText(', ');
        $this->assertSame('Hello, ', $block->text);
        $this->assertTrue($block->streaming);

        $block = $block->appendText('world!');
        $this->assertSame('Hello, world!', $block->text);
        $this->assertTrue($block->streaming);
    }

    public function testAppendTextWithEmptyStringIsNoop(): void
    {
        $block = new TranscriptBlock(
            id: 's5',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_s',
            seq: 5,
            text: 'unchanged',
            streaming: true,
        );

        $result = $block->appendText('');

        $this->assertSame($block, $result);
    }

    public function testStreamingTransitionToComplete(): void
    {
        // Simulate a full streaming lifecycle: start streaming -> deltas -> finalize
        $block = new TranscriptBlock(
            id: 'lifecycle_1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_lc',
            seq: 10,
            text: '',
            streaming: true,
        );

        $this->assertTrue($block->streaming);
        $this->assertSame('', $block->text);

        $block = $block->appendText('He');
        $block = $block->appendText('llo');
        $this->assertSame('Hello', $block->text);
        $this->assertTrue($block->streaming);

        $block = $block->appendText(' world');
        $block = $block->finalize();
        $this->assertSame('Hello world', $block->text);
        $this->assertFalse($block->streaming);
    }

    // ── with() edge cases ──────────────────────────────────────────────────

    public function testWithPreservesUnmodifiedFields(): void
    {
        $original = new TranscriptBlock(
            id: 'w1',
            kind: TranscriptBlockKindEnum::Error,
            runId: 'run_w',
            seq: 100,
            text: 'Something went wrong',
            meta: ['code' => 500],
            streaming: false,
            collapsed: true,
        );

        $updated = $original->with(streaming: true);

        $this->assertSame('w1', $updated->id);
        $this->assertSame(TranscriptBlockKindEnum::Error, $updated->kind);
        $this->assertSame('run_w', $updated->runId);
        $this->assertSame(100, $updated->seq);
        $this->assertSame('Something went wrong', $updated->text);
        $this->assertSame(['code' => 500], $updated->meta);
        $this->assertTrue($updated->streaming);
        $this->assertTrue($updated->collapsed);
    }

    public function testWithMultipleChangesAtOnce(): void
    {
        $original = new TranscriptBlock(
            id: 'w2',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'run_w',
            seq: 200,
            text: '',
            meta: [],
            streaming: true,
            collapsed: false,
        );

        $updated = $original->with(
            text: 'Completed',
            streaming: false,
            meta: ['status' => 'done'],
        );

        $this->assertSame('Completed', $updated->text);
        $this->assertFalse($updated->streaming);
        $this->assertSame(['status' => 'done'], $updated->meta);
        $this->assertFalse($updated->collapsed);
    }

    public function testWithMetaMergesProperly(): void
    {
        $original = new TranscriptBlock(
            id: 'w3',
            kind: TranscriptBlockKindEnum::ToolCall,
            runId: 'run_w',
            seq: 300,
            meta: ['tool_name' => 'bash'],
        );

        $updated = $original->with(meta: ['tool_name' => 'read', 'status' => 'done']);

        // with() replaces meta entirely, not merges (simpler, safer)
        $this->assertSame(['tool_name' => 'read', 'status' => 'done'], $updated->meta);
        $this->assertSame(['tool_name' => 'bash'], $original->meta);
    }
}
