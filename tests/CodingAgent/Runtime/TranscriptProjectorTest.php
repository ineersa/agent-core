<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranscriptProjector::class)]
#[CoversClass(TranscriptBlock::class)]
#[CoversClass(TranscriptBlockKindEnum::class)]
final class TranscriptProjectorTest extends TestCase
{
    private const string RUN_ID = 'run_001';

    private TranscriptProjector $projector;
    private int $seq = 0;

    protected function setUp(): void
    {
        $this->projector = new TranscriptProjector();
        $this->seq = 0;
    }

    // ── Tool call lifecycle ──────────────────────────────────────────────

    public function test_tool_call_started_creates_streaming_tool_call_block(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'read',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);

        $block = $blocks[0];
        self::assertSame('tool_call_tc_01', $block->id);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        self::assertSame('read', $block->text);
        self::assertTrue($block->streaming);
        self::assertSame('tc_01', $block->meta['tool_call_id']);
        self::assertSame('read', $block->meta['tool_name']);
    }

    public function test_tool_call_arguments_delta_appends_text(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => '{"command": "ls',
        ]);
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => ' -la"}',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('bash{"command": "ls -la"}', $block->text);
        self::assertTrue($block->streaming);
    }

    public function test_tool_call_arguments_delta_ignores_unknown_tool_call_id(): void
    {
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'nosuch',
            'delta' => 'xyz',
        ]);

        self::assertCount(0, $this->projector->blocks());
    }

    public function test_tool_call_arguments_completed_finalizes_block(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => '{"cmd":"ls"}',
        ]);
        $this->apply('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('bash(cmd: "ls")', $block->text);
        self::assertFalse($block->streaming);
        self::assertSame(['cmd' => 'ls'], $block->meta['arguments']);
    }

    public function test_tool_call_arguments_completed_without_started_creates_block(): void
    {
        // If arguments_completed arrives without a prior started event
        // (replay or out-of-order), the projector should create the block.
        $this->apply('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_99',
            'tool_name' => 'write',
            'arguments' => ['path' => '/tmp/x', 'content' => 'hello'],
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('tool_call_tc_99', $block->id);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        self::assertSame('write(path: "/tmp/x", content: "hello")', $block->text);
        self::assertFalse($block->streaming);
    }

    // ── Tool execution lifecycle ─────────────────────────────────────────

    public function test_tool_execution_started_creates_result_block(): void
    {
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('tool_result_tc_01', $block->id);
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        self::assertSame('Running…', $block->text);
        self::assertTrue($block->streaming);
    }

    public function test_tool_execution_output_delta_appends_text(): void
    {
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => 'line 1',
        ]);
        $this->apply('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => "\nline 2",
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame("Running…line 1\nline 2", $block->text);
    }

    public function test_tool_execution_completed_finalizes_result_block(): void
    {
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.completed', [
            'tool_call_id' => 'tc_01',
            'result' => 'total 0\ndrwxr-xr-x',
            'duration_ms' => 42,
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        self::assertSame('total 0\ndrwxr-xr-x', $block->text);
        self::assertFalse($block->streaming);
        self::assertFalse($block->meta['is_error']);
        self::assertSame(42, $block->meta['duration_ms']);
        self::assertSame('total 0\ndrwxr-xr-x', $block->meta['result']);
    }

    public function test_tool_execution_completed_without_start_creates_block(): void
    {
        $this->apply('tool_execution.completed', [
            'tool_call_id' => 'tc_99',
            'result' => 'done',
            'duration_ms' => 10,
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('done', $block->text);
        self::assertFalse($block->streaming);
    }

    public function test_tool_execution_failed_creates_error_result(): void
    {
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.failed', [
            'tool_call_id' => 'tc_01',
            'result' => 'command not found: xyz',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        self::assertSame('command not found: xyz', $block->text);
        self::assertTrue($block->meta['is_error']);
    }

    public function test_tool_execution_cancelled_marks_as_cancelled(): void
    {
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.cancelled', [
            'tool_call_id' => 'tc_01',
            'cancelled' => true,
            'timed_out' => false,
        ]);

        $block = $this->projector->blocks()[0];
        self::assertTrue($block->meta['cancelled']);
        self::assertFalse($block->meta['timed_out']);
        self::assertSame('Cancelled', $block->text);
    }

    public function test_tool_execution_cancelled_with_timeout(): void
    {
        $this->apply('tool_execution.cancelled', [
            'tool_call_id' => 'tc_01',
            'cancelled' => true,
            'timed_out' => true,
        ]);

        $block = $this->projector->blocks()[0];
        self::assertTrue($block->meta['timed_out']);
        self::assertSame('Timed out', $block->text);
    }

    // ── HITL ─────────────────────────────────────────────────────────────

    public function test_human_input_requested_creates_question_block(): void
    {
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'What is the answer?',
            'schema' => ['type' => 'string'],
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('hitl_q_01', $block->id);
        self::assertSame(TranscriptBlockKindEnum::Question, $block->kind);
        self::assertSame('What is the answer?', $block->text);
        self::assertFalse($block->streaming);
        self::assertSame('q_01', $block->meta['question_id']);
        self::assertSame('pending', $block->meta['status']);
        self::assertSame(['type' => 'string'], $block->meta['schema']);
    }

    public function test_human_input_requested_with_tool_call_info(): void
    {
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'approval',
            'prompt' => 'Approve deletion?',
            'tool_call_id' => 'tc_42',
            'tool_name' => 'ask_human',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('tc_42', $block->meta['tool_call_id']);
        self::assertSame('ask_human', $block->meta['tool_name']);
    }

    public function test_human_input_answered_updates_block(): void
    {
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'Name?',
        ]);
        $this->apply('human_input.answered', [
            'question_id' => 'q_01',
            'answer' => 'Alice',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('answered', $block->meta['status']);
        self::assertSame('Alice', $block->meta['answer']);
        self::assertStringContainsString('→ Alice', $block->text);
    }

    public function test_human_input_answered_ignores_unknown_question(): void
    {
        $this->apply('human_input.answered', [
            'question_id' => 'nosuch',
            'answer' => 'x',
        ]);

        self::assertCount(0, $this->projector->blocks());
    }

    public function test_human_input_rejected_updates_block(): void
    {
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'Name?',
        ]);
        $this->apply('human_input.rejected', [
            'question_id' => 'q_01',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('rejected', $block->meta['status']);
        self::assertStringContainsString('(rejected)', $block->text);
    }

    // ── Approval ─────────────────────────────────────────────────────────

    public function test_approval_requested_creates_approval_block(): void
    {
        $this->apply('approval.requested', [
            'request_id' => 'appr_01',
            'prompt' => 'Delete all files?',
            'tool_call_id' => 'tc_99',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame('approval_appr_01', $block->id);
        self::assertSame(TranscriptBlockKindEnum::Approval, $block->kind);
        self::assertStringContainsString('Delete all files?', $block->text);
        self::assertSame('pending', $block->meta['status']);
        self::assertSame('tc_99', $block->meta['tool_call_id']);
    }

    public function test_approval_approved_updates_block(): void
    {
        $this->apply('approval.requested', [
            'request_id' => 'appr_01',
            'prompt' => 'Delete files?',
        ]);
        $this->apply('approval.approved', [
            'request_id' => 'appr_01',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('approved', $block->meta['status']);
        self::assertStringEndsWith('✓', $block->text);
    }

    public function test_approval_rejected_updates_block(): void
    {
        $this->apply('approval.requested', [
            'request_id' => 'appr_01',
            'prompt' => 'Delete files?',
        ]);
        $this->apply('approval.rejected', [
            'request_id' => 'appr_01',
        ]);

        $block = $this->projector->blocks()[0];
        self::assertSame('rejected', $block->meta['status']);
        self::assertStringEndsWith('✗', $block->text);
    }

    public function test_approval_approved_ignores_unknown_request(): void
    {
        $this->apply('approval.approved', [
            'request_id' => 'nosuch',
        ]);

        self::assertCount(0, $this->projector->blocks());
    }

    // ── Cancellation ─────────────────────────────────────────────────────

    public function test_cancellation_requested_does_not_create_block(): void
    {
        $this->apply('cancellation.requested', [
            'reason' => 'user_cancelled',
        ]);

        self::assertCount(0, $this->projector->blocks());
    }

    public function test_operation_cancelled_creates_cancelled_block(): void
    {
        $this->apply('operation.cancelled', [
            'reason' => 'timeout',
            'operation_id' => 'op_42',
            'operation_type' => 'tool',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        $block = $blocks[0];
        self::assertSame(TranscriptBlockKindEnum::Cancelled, $block->kind);
        self::assertStringContainsString('tool', $block->text);
        self::assertStringContainsString('op_42', $block->text);
        self::assertSame('timeout', $block->meta['reason']);
        self::assertFalse($block->streaming);
    }

    public function test_turn_cancelled_finalizes_streaming_blocks_and_creates_cancelled_block(): void
    {
        // Start a streaming tool call and tool execution
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);

        // Both should be streaming
        $blocks = $this->projector->blocks();
        self::assertTrue($blocks[0]->streaming);
        self::assertTrue($blocks[1]->streaming);

        $this->apply('turn.cancelled', [
            'reason' => 'user_cancelled',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(3, $blocks, 'Should have 2 finalized blocks + 1 cancelled block');

        // Formerly streaming blocks are now finalized
        self::assertFalse($blocks[0]->streaming, 'Tool call block should be finalized');
        self::assertFalse($blocks[1]->streaming, 'Tool result block should be finalized');

        // Cancelled block was appended
        $cancelBlock = $blocks[2];
        self::assertSame(TranscriptBlockKindEnum::Cancelled, $cancelBlock->kind);
        self::assertStringContainsString('turn cancelled', $cancelBlock->text);
        self::assertFalse($cancelBlock->streaming);
    }

    public function test_run_cancelled_finalizes_streaming_blocks_and_creates_cancelled_block(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'read',
        ]);
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'read',
        ]);

        $this->apply('run.cancelled', [
            'reason' => 'provider_aborted',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(3, $blocks);

        self::assertFalse($blocks[0]->streaming);
        self::assertFalse($blocks[1]->streaming);

        $cancelBlock = $blocks[2];
        self::assertSame(TranscriptBlockKindEnum::Cancelled, $cancelBlock->kind);
        self::assertStringContainsString('run cancelled', $cancelBlock->text);
        self::assertStringContainsString('provider_aborted', $cancelBlock->text);
    }

    public function test_turn_cancelled_does_not_affect_non_streaming_blocks(): void
    {
        // A completed question block should stay unchanged
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'test?',
        ]);

        $this->apply('turn.cancelled', [
            'reason' => 'user_cancelled',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(2, $blocks);
        self::assertSame(TranscriptBlockKindEnum::Question, $blocks[0]->kind);
        self::assertFalse($blocks[0]->streaming);
    }

    // ── Sequence ordering ────────────────────────────────────────────────

    public function test_blocks_are_ordered_by_insertion(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'test?',
        ]);
        $this->apply('approval.requested', [
            'request_id' => 'appr_01',
            'prompt' => 'ok?',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(3, $blocks);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind);
        self::assertSame(TranscriptBlockKindEnum::Question, $blocks[1]->kind);
        self::assertSame(TranscriptBlockKindEnum::Approval, $blocks[2]->kind);
    }

    public function test_block_updates_preserve_position(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'test?',
        ]);

        // Update the first block
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => 'args',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(2, $blocks);
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind);
        self::assertStringContainsString('args', $blocks[0]->text);
        self::assertSame(TranscriptBlockKindEnum::Question, $blocks[1]->kind);
    }

    // ── Full scenarios ───────────────────────────────────────────────────

    public function test_full_tool_lifecycle(): void
    {
        // Tool call: started → args streaming → completed
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => '{"c',
        ]);
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => 'md":"ls"}',
        ]);
        $this->apply('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        // Tool execution: started → output streaming → completed
        $this->apply('tool_execution.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => 'file1.txt',
        ]);
        $this->apply('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => "\nfile2.txt",
        ]);
        $this->apply('tool_execution.completed', [
            'tool_call_id' => 'tc_01',
            'result' => "file1.txt\nfile2.txt",
            'duration_ms' => 150,
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(2, $blocks);

        // Tool call block should be finalized
        $callBlock = $blocks[0];
        self::assertSame(TranscriptBlockKindEnum::ToolCall, $callBlock->kind);
        self::assertFalse($callBlock->streaming);
        self::assertSame(['cmd' => 'ls'], $callBlock->meta['arguments']);

        // Tool result block should be finalized
        $resultBlock = $blocks[1];
        self::assertSame(TranscriptBlockKindEnum::ToolResult, $resultBlock->kind);
        self::assertFalse($resultBlock->streaming);
        self::assertSame("file1.txt\nfile2.txt", $resultBlock->text);
        self::assertSame(150, $resultBlock->meta['duration_ms']);
    }

    public function test_full_hitl_round_trip(): void
    {
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'What is your name?',
        ]);

        // Question block exists and is pending
        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        self::assertSame('pending', $blocks[0]->meta['status']);

        $this->apply('human_input.answered', [
            'question_id' => 'q_01',
            'answer' => 'Bob',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        self::assertSame('answered', $blocks[0]->meta['status']);
    }

    public function test_full_approval_round_trip(): void
    {
        $this->apply('approval.requested', [
            'request_id' => 'appr_01',
            'prompt' => 'Delete generated files?',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(1, $blocks);
        self::assertSame('pending', $blocks[0]->meta['status']);

        $this->apply('approval.approved', [
            'request_id' => 'appr_01',
        ]);

        $blocks = $this->projector->blocks();
        self::assertSame('approved', $blocks[0]->meta['status']);
        self::assertStringEndsWith('✓', $blocks[0]->text);
    }

    // ── Multiple tool calls ──────────────────────────────────────────────

    public function test_multiple_tool_calls_are_independent(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'read',
        ]);
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_02',
            'tool_name' => 'write',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(2, $blocks);
        self::assertSame('tool_call_tc_01', $blocks[0]->id);
        self::assertSame('tool_call_tc_02', $blocks[1]->id);

        // Append to first only
        $this->apply('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01',
            'delta' => 'first_args',
        ]);

        $blocks = $this->projector->blocks();
        self::assertStringContainsString('first_args', $blocks[0]->text);
        self::assertSame('write', $blocks[1]->text);
    }

    // ── Event sequence tracking ──────────────────────────────────────────

    public function test_blocks_have_monotonic_seq_numbers(): void
    {
        $this->apply('tool_call.started', [
            'tool_call_id' => 'tc_01',
            'tool_name' => 'bash',
        ]);
        $this->apply('human_input.requested', [
            'request_id' => 'req_01',
            'question_id' => 'q_01',
            'kind' => 'text',
            'prompt' => 'test?',
        ]);
        $this->apply('operation.cancelled', [
            'reason' => 'timeout',
            'operation_id' => 'op_01',
        ]);

        $blocks = $this->projector->blocks();
        self::assertCount(3, $blocks);
        self::assertLessThan($blocks[2]->seq, $blocks[1]->seq, 'Earlier blocks have lower seq');
        self::assertLessThan($blocks[1]->seq, $blocks[0]->seq, 'Earliest block has lowest seq');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function apply(string $type, array $payload = []): void
    {
        $this->projector->apply(new RuntimeEvent(
            type: $type,
            runId: self::RUN_ID,
            seq: $this->seq++,
            payload: $payload,
        ));
    }
}
