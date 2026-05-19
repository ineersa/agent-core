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

    public function test_construct_with_minimal_fields(): void
    {
        $block = new TranscriptBlock(
            id: 'msg_1',
            kind: TranscriptBlockKindEnum::UserMessage,
            runId: 'run_abc',
            seq: 1,
        );

        self::assertSame('msg_1', $block->id);
        self::assertSame(TranscriptBlockKindEnum::UserMessage, $block->kind);
        self::assertSame('run_abc', $block->runId);
        self::assertSame(1, $block->seq);
        self::assertSame('', $block->text);
        self::assertSame([], $block->meta);
        self::assertFalse($block->streaming);
        self::assertFalse($block->collapsed);
    }

    public function test_construct_with_all_fields(): void
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

        self::assertSame('tool_1', $block->id);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        self::assertSame('run_xyz', $block->runId);
        self::assertSame(5, $block->seq);
        self::assertSame('bash: ls -la', $block->text);
        self::assertSame(['tool_name' => 'bash', 'tool_call_id' => 'call_42'], $block->meta);
        self::assertTrue($block->streaming);
        self::assertFalse($block->collapsed);
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
    public function test_construct_all_block_kinds(TranscriptBlockKindEnum $kind): void
    {
        $block = new TranscriptBlock(
            id: 'b1',
            kind: $kind,
            runId: 'run_test',
            seq: 1,
            text: 'text for '.$kind->value,
        );

        self::assertSame($kind, $block->kind);
        self::assertSame('text for '.$kind->value, $block->text);
    }

    // ── TranscriptBlockKindEnum values ─────────────────────────────────────

    public function test_enum_values(): void
    {
        $expected = [
            'user_message',
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
            fn (TranscriptBlockKindEnum $k): string => $k->value,
            TranscriptBlockKindEnum::cases(),
        );

        self::assertSame($expected, $actual);
    }

    public function test_enum_from_string(): void
    {
        self::assertSame(
            TranscriptBlockKindEnum::AssistantMessage,
            TranscriptBlockKindEnum::from('assistant_message'),
        );
    }

    public function test_enum_tryFrom_invalid(): void
    {
        self::assertNull(TranscriptBlockKindEnum::tryFrom('invalid_kind'));
    }

    // ── Symfony Serializer round-trip ───────────────────────────────────────

    public function test_normalize_produces_correct_array(): void
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

        self::assertSame('msg_2', $arr['id']);
        self::assertSame('assistant_message', $arr['kind']);
        self::assertSame('run_a', $arr['runId']);
        self::assertSame(3, $arr['seq']);
        self::assertSame('Hello, world!', $arr['text']);
        self::assertSame(['model' => 'claude-3'], $arr['meta']);
        self::assertFalse($arr['streaming']);
        self::assertFalse($arr['collapsed']);
    }

    public function test_denormalize_reconstructs_block(): void
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

        self::assertInstanceOf(TranscriptBlock::class, $block);
        self::assertSame('msg_3', $block->id);
        self::assertSame(TranscriptBlockKindEnum::AssistantThinking, $block->kind);
        self::assertSame('run_b', $block->runId);
        self::assertSame(7, $block->seq);
        self::assertSame('Let me think about this...', $block->text);
        self::assertSame(['reasoning' => 'high'], $block->meta);
        self::assertTrue($block->streaming);
        self::assertFalse($block->collapsed);
    }

    public function test_roundtrip_preserves_all_data(): void
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
        self::assertIsArray($normalized);
        $reconstructed = $this->serializer->denormalize($normalized, TranscriptBlock::class);

        self::assertInstanceOf(TranscriptBlock::class, $reconstructed);
        self::assertSame($original->id, $reconstructed->id);
        self::assertSame($original->kind, $reconstructed->kind);
        self::assertSame($original->runId, $reconstructed->runId);
        self::assertSame($original->seq, $reconstructed->seq);
        self::assertSame($original->text, $reconstructed->text);
        self::assertSame($original->meta, $reconstructed->meta);
        self::assertSame($original->streaming, $reconstructed->streaming);
        self::assertSame($original->collapsed, $reconstructed->collapsed);
    }

    public function test_denormalize_missing_required_fields_throws(): void
    {
        $this->expectException(MissingConstructorArgumentsException::class);

        $this->serializer->denormalize([], TranscriptBlock::class);
    }

    public function test_normalize_denormalize_all_enum_kinds(): void
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
            self::assertIsArray($normalized);

            $restored = $this->serializer->denormalize($normalized, TranscriptBlock::class);
            self::assertInstanceOf(TranscriptBlock::class, $restored);
            self::assertSame($kind, $restored->kind);
            self::assertSame($original->text, $restored->text);
        }
    }

    // ── Streaming state transitions ────────────────────────────────────────

    public function test_streaming_defaults_to_false(): void
    {
        $block = new TranscriptBlock(
            id: 's1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run_s',
            seq: 1,
        );

        self::assertFalse($block->streaming);
    }

    public function test_with_changes_text_immutably(): void
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
        self::assertSame('', $original->text);
        self::assertTrue($original->streaming);

        // New block has updated text
        self::assertSame('Hello', $updated->text);
        self::assertSame('s2', $updated->id);
        self::assertSame($original->kind, $updated->kind);
        self::assertSame($original->runId, $updated->runId);
        self::assertSame($original->seq, $updated->seq);
        self::assertSame($original->meta, $updated->meta);
        self::assertSame($original->streaming, $updated->streaming);
        self::assertSame($original->collapsed, $updated->collapsed);
    }

    public function test_finalize_sets_streaming_false(): void
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

        self::assertTrue($streamingBlock->streaming, 'Original should still be streaming');
        self::assertFalse($finalized->streaming, 'Finalized should not be streaming');
        self::assertSame('partial thinking...', $finalized->text);
        self::assertSame($streamingBlock->id, $finalized->id);
        self::assertSame($streamingBlock->kind, $finalized->kind);
    }

    public function test_appendText_accumulates_deltas(): void
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
        self::assertSame('Hello, ', $block->text);
        self::assertTrue($block->streaming);

        $block = $block->appendText('world!');
        self::assertSame('Hello, world!', $block->text);
        self::assertTrue($block->streaming);
    }

    public function test_appendText_with_empty_string_is_noop(): void
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

        self::assertSame($block, $result);
    }

    public function test_streaming_transition_to_complete(): void
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

        self::assertTrue($block->streaming);
        self::assertSame('', $block->text);

        $block = $block->appendText('He');
        $block = $block->appendText('llo');
        self::assertSame('Hello', $block->text);
        self::assertTrue($block->streaming);

        $block = $block->appendText(' world');
        $block = $block->finalize();
        self::assertSame('Hello world', $block->text);
        self::assertFalse($block->streaming);
    }

    // ── with() edge cases ──────────────────────────────────────────────────

    public function test_with_preserves_unmodified_fields(): void
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

        self::assertSame('w1', $updated->id);
        self::assertSame(TranscriptBlockKindEnum::Error, $updated->kind);
        self::assertSame('run_w', $updated->runId);
        self::assertSame(100, $updated->seq);
        self::assertSame('Something went wrong', $updated->text);
        self::assertSame(['code' => 500], $updated->meta);
        self::assertTrue($updated->streaming);
        self::assertTrue($updated->collapsed);
    }

    public function test_with_multiple_changes_at_once(): void
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

        self::assertSame('Completed', $updated->text);
        self::assertFalse($updated->streaming);
        self::assertSame(['status' => 'done'], $updated->meta);
        self::assertFalse($updated->collapsed);
    }

    public function test_with_meta_merges_properly(): void
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
        self::assertSame(['tool_name' => 'read', 'status' => 'done'], $updated->meta);
        self::assertSame(['tool_name' => 'bash'], $original->meta);
    }
}
