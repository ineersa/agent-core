<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(TranscriptProjector::class)]
#[CoversClass(TranscriptProjectionState::class)]
#[CoversClass(TranscriptBlock::class)]
#[CoversClass(TranscriptBlockKindEnum::class)]
final class TranscriptProjectorTest extends TestCase
{
    private const string RUN_ID = 'run_1';

    private TranscriptProjector $projector;
    private int $seq = 0;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $state = new TranscriptProjectionState();

        $dispatcher->addSubscriber(new UserMessageProjectionSubscriber());
        $dispatcher->addSubscriber(new AssistantStreamProjectionSubscriber());
        $dispatcher->addSubscriber(new ToolProjectionSubscriber());
        $dispatcher->addSubscriber(new HitlProjectionSubscriber());
        $dispatcher->addSubscriber(new CancellationProjectionSubscriber());

        $this->projector = new TranscriptProjector($dispatcher, $state);
        $this->seq = 0;
    }

    // ── Initial state ────────────────────────────────────────────────────────

    public function testInitialStateHasEmptyBlocks(): void
    {
        $this->assertSame([], $this->projector->blocks());
    }

    // ── Reset ────────────────────────────────────────────────────────────────

    public function testResetClearsAllState(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'Hi']);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 'b1',
        ]);

        $this->assertNotEmpty($this->projector->blocks());

        $this->projector->reset();

        $this->assertSame([], $this->projector->blocks());
    }

    public function testResetThenReacceptProducesSameSeq(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'A']);
        $seqAfterFirst = $this->projector->blocks()[0]->seq;

        $this->projector->reset();
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'A']);
        $seqAfterReset = $this->projector->blocks()[0]->seq;

        $this->assertSame($seqAfterFirst, $seqAfterReset, 'Reset must renumber blocks from 0');
    }

    // ── User message ─────────────────────────────────────────────────────────

    public function testUserMessageCreatesBlock(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'msg_1', 'text' => 'Hello, world!']);

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
        $this->accept('user.message_submitted', ['message_id' => 'm_empty']);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('', $blocks[0]->text);
    }

    public function testMultipleUserMessages(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'Q1']);
        $this->accept('user.message_submitted', ['message_id' => 'u2', 'text' => 'Q2']);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('u1', $blocks[0]->id);
        $this->assertSame('u2', $blocks[1]->id);
        $this->assertSame(0, $blocks[0]->seq);
        $this->assertSame(1, $blocks[1]->seq);
    }

    // ── Assistant text stream ─────────────────────────────────────────────────

    public function testAssistantTextStreamCreatesAndAccumulatesBlock(): void
    {
        $this->accept('assistant.message_started', ['message_id' => 'a1']);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'Hello']);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => ', ']);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'world!']);

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
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'Hey']);
        $this->accept('assistant.text_completed', [
            'block_id' => 'a1_t0', 'text' => 'Hey there',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming, 'Block should be finalized');
        $this->assertSame('Hey there', $blocks[0]->text);
    }

    // ── Assistant thinking stream ────────────────────────────────────────────

    public function testAssistantThinkingStreamCreatesAndAccumulatesBlock(): void
    {
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_th0',
        ]);
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'delta' => 'Let me think...']);
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'delta' => ' done.']);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('a1_th0', $blocks[0]->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('Let me think... done.', $blocks[0]->text);
        $this->assertTrue($blocks[0]->streaming);
        $this->assertTrue($blocks[0]->collapsed);
        $this->assertSame(1, $blocks[0]->meta['content_index']);
    }

    public function testAssistantThinkingCompletedFinalizesBlock(): void
    {
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0',
        ]);
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'delta' => 'Hmm']);
        $this->accept('assistant.thinking_completed', [
            'block_id' => 'a1_th0', 'text' => 'Hmm, interesting.',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame('Hmm, interesting.', $blocks[0]->text);
    }

    public function testThinkingBlockIsCollapsedByDefault(): void
    {
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'block_id' => 'th1',
        ]);

        $this->assertTrue($this->projector->blocks()[0]->collapsed);
    }

    public function testTextBlockIsNotCollapsed(): void
    {
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 't1',
        ]);

        $this->assertFalse($this->projector->blocks()[0]->collapsed);
    }

    // ── Interleaved text + thinking ──────────────────────────────────────────

    public function testInterleavedTextAndThinkingBlocks(): void
    {
        $this->accept('assistant.message_started', ['message_id' => 'a1']);
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0',
        ]);
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'delta' => 'Analyzing...']);
        $this->accept('assistant.thinking_completed', [
            'block_id' => 'a1_th0', 'text' => 'Analyzing the request.',
        ]);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'The answer is 42.']);
        $this->accept('assistant.text_completed', ['block_id' => 'a1_t0']);
        $this->accept('assistant.message_completed', ['message_id' => 'a1']);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('Analyzing the request.', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[1]->kind);
        $this->assertSame('The answer is 42.', $blocks[1]->text);
        $this->assertFalse($blocks[1]->streaming);
    }

    // ── message_completed and message_failed ─────────────────────────────────

    public function testMessageCompletedFinalizesStreamingBlocks(): void
    {
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'Streaming...']);

        $this->assertTrue($this->projector->blocks()[0]->streaming);

        $this->accept('assistant.message_completed', ['message_id' => 'a1']);

        $this->assertFalse($this->projector->blocks()[0]->streaming);
    }

    public function testMessageFailedFinalizesStreamingAndAppendsErrorBlock(): void
    {
        $this->accept('assistant.message_started', ['message_id' => 'a1']);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'delta' => 'Partial text...']);
        $this->accept('assistant.message_failed', [
            'message_id' => 'a1', 'stop_reason' => 'provider_error', 'text' => 'API rate limit exceeded',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('a1_t0', $blocks[0]->id);
        $this->assertSame('Partial text...', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[1]->kind);
        $this->assertSame('API rate limit exceeded', $blocks[1]->text);
        $this->assertSame('a1', $blocks[1]->meta['message_id']);
        $this->assertSame('provider_error', $blocks[1]->meta['stop_reason']);
    }

    public function testMessageFailedWithoutStreamingBlocks(): void
    {
        $this->accept('assistant.message_failed', [
            'message_id' => 'a1', 'text' => 'Timeout', 'stop_reason' => 'timeout',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[0]->kind);
        $this->assertSame('Timeout', $blocks[0]->text);
    }

    public function testErrorBlockFromMessageFailedIsNotStreaming(): void
    {
        $this->accept('assistant.message_failed', [
            'message_id' => 'a1', 'text' => 'Failed',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertFalse($blocks[0]->collapsed);
    }

    // ── Assistant meta ───────────────────────────────────────────────────────

    public function testAssistantMetaIncludesModelAndStopReason(): void
    {
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'b1',
            'model' => 'claude/sonnet-4', 'stop_reason' => 'stop',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertSame('claude/sonnet-4', $blocks[0]->meta['model']);
        $this->assertSame('stop', $blocks[0]->meta['stop_reason']);
    }

    // ── Message marker ───────────────────────────────────────────────────────

    public function testMessageStartedWithoutBlocksIsNoOp(): void
    {
        $this->accept('assistant.message_started', ['message_id' => 'a1']);

        $this->assertSame([], $this->projector->blocks(), 'message_started alone does not create blocks');
    }

    // ── Tool call lifecycle ──────────────────────────────────────────────────

    public function testToolCallStartedCreatesStreamingToolCallBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('tool_call_tc_01', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        $this->assertSame('read', $block->text);
        $this->assertTrue($block->streaming);
        $this->assertSame('tc_01', $block->meta['tool_call_id']);
        $this->assertSame('read', $block->meta['tool_name']);
    }

    public function testToolCallArgumentsDeltaAppendsText(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => '{"command": "ls',
        ]);
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => ' -la"}',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('bash{"command": "ls -la"}', $block->text);
        $this->assertTrue($block->streaming);
    }

    public function testToolCallArgumentsDeltaIgnoresUnknownToolCallId(): void
    {
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'nosuch', 'delta' => 'xyz',
        ]);

        $this->assertCount(0, $this->projector->blocks());
    }

    public function testToolCallArgumentsCompletedFinalizesBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => '{"cmd":"ls"}',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('bash{"cmd":"ls"}(cmd: "ls")', $block->text);
        $this->assertFalse($block->streaming);
        $this->assertSame(['cmd' => 'ls'], $block->meta['arguments']);
    }

    public function testToolCallArgumentsCompletedWithoutStartedCreatesBlock(): void
    {
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_99', 'tool_name' => 'write',
            'arguments' => ['path' => '/tmp/x', 'content' => 'hello'],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('tool_call_tc_99', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $block->kind);
        $this->assertSame('write(path: "/tmp/x", content: "hello")', $block->text);
        $this->assertFalse($block->streaming);
    }

    // ── Tool execution lifecycle ─────────────────────────────────────────────

    public function testToolExecutionStartedCreatesResultBlock(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('tool_result_tc_01', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        $this->assertSame('Running…', $block->text);
        $this->assertTrue($block->streaming);
    }

    public function testToolExecutionOutputDeltaAppendsText(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01', 'delta' => 'line 1',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01', 'delta' => "\nline 2",
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame("Running…line 1\nline 2", $block->text);
    }

    public function testToolExecutionCompletedFinalizesResultBlock(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_01', 'result' => "total 0\ndrwxr-xr-x",
            'duration_ms' => 42,
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        $this->assertSame("total 0\ndrwxr-xr-x", $block->text);
        $this->assertFalse($block->streaming);
        $this->assertFalse($block->meta['is_error']);
        $this->assertSame(42, $block->meta['duration_ms']);
    }

    public function testToolExecutionCompletedWithoutStartCreatesBlock(): void
    {
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_99', 'result' => 'done', 'duration_ms' => 10,
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('done', $block->text);
        $this->assertFalse($block->streaming);
    }

    public function testToolExecutionFailedCreatesErrorResult(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.failed', [
            'tool_call_id' => 'tc_01', 'result' => 'command not found: xyz',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $block->kind);
        $this->assertSame('command not found: xyz', $block->text);
        $this->assertTrue($block->meta['is_error']);
    }

    public function testToolExecutionCancelledMarksAsCancelled(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.cancelled', [
            'tool_call_id' => 'tc_01', 'cancelled' => true, 'timed_out' => false,
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertTrue($block->meta['cancelled']);
        $this->assertFalse($block->meta['timed_out']);
        $this->assertSame('Cancelled', $block->text);
    }

    public function testToolExecutionCancelledWithTimeout(): void
    {
        $this->accept('tool_execution.cancelled', [
            'tool_call_id' => 'tc_01', 'cancelled' => true, 'timed_out' => true,
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertTrue($block->meta['timed_out']);
        $this->assertSame('Timed out', $block->text);
    }

    // ── HITL ─────────────────────────────────────────────────────────────────

    public function testHumanInputRequestedCreatesQuestionBlock(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'What is the answer?',
            'schema' => ['type' => 'string'],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('hitl_q_01', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::Question, $block->kind);
        $this->assertSame('What is the answer?', $block->text);
        $this->assertFalse($block->streaming);
        $this->assertSame('q_01', $block->meta['question_id']);
        $this->assertSame('pending', $block->meta['status']);
        $this->assertSame(['type' => 'string'], $block->meta['schema']);
    }

    public function testHumanInputRequestedWithToolCallInfo(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'approval', 'prompt' => 'Approve deletion?',
            'tool_call_id' => 'tc_42', 'tool_name' => 'ask_human',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('tc_42', $block->meta['tool_call_id']);
        $this->assertSame('ask_human', $block->meta['tool_name']);
    }

    public function testHumanInputAnsweredUpdatesBlock(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'Name?',
        ]);
        $this->accept('human_input.answered', [
            'question_id' => 'q_01', 'answer' => 'Alice',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('answered', $block->meta['status']);
        $this->assertSame('Alice', $block->meta['answer']);
        $this->assertStringContainsString('→ Alice', $block->text);
    }

    public function testHumanInputAnsweredIgnoresUnknownQuestion(): void
    {
        $this->accept('human_input.answered', [
            'question_id' => 'nosuch', 'answer' => 'x',
        ]);

        $this->assertCount(0, $this->projector->blocks());
    }

    public function testHumanInputRejectedUpdatesBlock(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'Name?',
        ]);
        $this->accept('human_input.rejected', ['question_id' => 'q_01']);

        $block = $this->projector->blocks()[0];
        $this->assertSame('rejected', $block->meta['status']);
        $this->assertStringContainsString('(rejected)', $block->text);
    }

    // ── Approval ─────────────────────────────────────────────────────────────

    public function testApprovalRequestedCreatesApprovalBlock(): void
    {
        $this->accept('approval.requested', [
            'request_id' => 'appr_01', 'prompt' => 'Delete all files?',
            'tool_call_id' => 'tc_99',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('approval_appr_01', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::Approval, $block->kind);
        $this->assertStringContainsString('Delete all files?', $block->text);
        $this->assertSame('pending', $block->meta['status']);
        $this->assertSame('tc_99', $block->meta['tool_call_id']);
    }

    public function testApprovalApprovedUpdatesBlock(): void
    {
        $this->accept('approval.requested', [
            'request_id' => 'appr_01', 'prompt' => 'Delete files?',
        ]);
        $this->accept('approval.approved', ['request_id' => 'appr_01']);

        $block = $this->projector->blocks()[0];
        $this->assertSame('approved', $block->meta['status']);
        $this->assertStringEndsWith('✓', $block->text);
    }

    public function testApprovalRejectedUpdatesBlock(): void
    {
        $this->accept('approval.requested', [
            'request_id' => 'appr_01', 'prompt' => 'Delete files?',
        ]);
        $this->accept('approval.rejected', ['request_id' => 'appr_01']);

        $block = $this->projector->blocks()[0];
        $this->assertSame('rejected', $block->meta['status']);
        $this->assertStringEndsWith('✗', $block->text);
    }

    public function testApprovalApprovedIgnoresUnknownRequest(): void
    {
        $this->accept('approval.approved', ['request_id' => 'nosuch']);

        $this->assertCount(0, $this->projector->blocks());
    }

    // ── Cancellation ─────────────────────────────────────────────────────────

    public function testCancellationRequestedDoesNotCreateBlock(): void
    {
        $this->accept('cancellation.requested', ['reason' => 'user_cancelled']);

        $this->assertCount(0, $this->projector->blocks());
    }

    public function testOperationCancelledCreatesCancelledBlock(): void
    {
        $this->accept('operation.cancelled', [
            'reason' => 'timeout', 'operation_id' => 'op_42', 'operation_type' => 'tool',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::Cancelled, $block->kind);
        $this->assertStringContainsString('tool', $block->text);
        $this->assertStringContainsString('op_42', $block->text);
        $this->assertSame('timeout', $block->meta['reason']);
        $this->assertFalse($block->streaming);
    }

    public function testTurnCancelledFinalizesStreamingBlocksAndCreatesCancelledBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertTrue($blocks[0]->streaming);
        $this->assertTrue($blocks[1]->streaming);

        $this->accept('turn.cancelled', ['reason' => 'user_cancelled']);

        $blocks = $this->projector->blocks();
        $this->assertCount(3, $blocks, 'Should have 2 finalized blocks + 1 cancelled block');
        $this->assertFalse($blocks[0]->streaming, 'Tool call block should be finalized');
        $this->assertFalse($blocks[1]->streaming, 'Tool result block should be finalized');

        $cancelBlock = $blocks[2];
        $this->assertSame(TranscriptBlockKindEnum::Cancelled, $cancelBlock->kind);
        $this->assertStringContainsString('turn cancelled', $cancelBlock->text);
        $this->assertFalse($cancelBlock->streaming);
    }

    public function testRunCancelledFinalizesStreamingBlocksAndCreatesCancelledBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);

        $this->accept('run.cancelled', ['reason' => 'provider_aborted']);

        $blocks = $this->projector->blocks();
        $this->assertCount(3, $blocks);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertFalse($blocks[1]->streaming);

        $cancelBlock = $blocks[2];
        $this->assertSame(TranscriptBlockKindEnum::Cancelled, $cancelBlock->kind);
        $this->assertStringContainsString('run cancelled', $cancelBlock->text);
        $this->assertStringContainsString('provider_aborted', $cancelBlock->text);
    }

    public function testTurnCancelledDoesNotAffectNonStreamingBlocks(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'test?',
        ]);

        $this->accept('turn.cancelled', ['reason' => 'user_cancelled']);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::Question, $blocks[0]->kind);
        $this->assertFalse($blocks[0]->streaming);
    }

    // ── Cancellation run-scoping ─────────────────────────────────────────────

    public function testTurnCancelledOnlyFinalizesSameRunBlocks(): void
    {
        // Add streaming block for run_1
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ], self::RUN_ID);

        // Add streaming block for a different run
        $this->acceptSameSeq('tool_call.started', [
            'tool_call_id' => 'tc_02', 'tool_name' => 'read',
        ], 'run_other');

        $this->assertTrue($this->projector->blocks()[0]->streaming);
        $this->assertTrue($this->projector->blocks()[1]->streaming);

        // Cancel turn on run_1 only
        $this->accept('turn.cancelled', ['reason' => 'user_cancelled'], self::RUN_ID);

        $blocks = $this->projector->blocks();
        $this->assertFalse($blocks[0]->streaming, 'run_1 block should be finalized');
        $this->assertTrue($blocks[1]->streaming, 'run_other block should remain streaming');
    }

    // ── Sequence ordering ────────────────────────────────────────────────────

    public function testBlocksAreOrderedByInsertion(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'test?',
        ]);
        $this->accept('approval.requested', [
            'request_id' => 'appr_01', 'prompt' => 'ok?',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(3, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind);
        $this->assertSame(TranscriptBlockKindEnum::Question, $blocks[1]->kind);
        $this->assertSame(TranscriptBlockKindEnum::Approval, $blocks[2]->kind);
    }

    public function testBlockUpdatesPreservePosition(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'test?',
        ]);

        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => 'args',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind);
        $this->assertStringContainsString('args', $blocks[0]->text);
        $this->assertSame(TranscriptBlockKindEnum::Question, $blocks[1]->kind);
    }

    public function testBlocksHaveMonotonicSeqNumbers(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'test?',
        ]);
        $this->accept('operation.cancelled', [
            'reason' => 'timeout', 'operation_id' => 'op_01',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(3, $blocks);
        $this->assertSame(0, $blocks[0]->seq);
        $this->assertSame(1, $blocks[1]->seq);
        $this->assertSame(2, $blocks[2]->seq);
    }

    public function testSeqIsMonotonicFullSuite(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'Hi']);
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'block_id' => 'th1',
        ]);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 't1',
        ]);
        $this->accept('assistant.message_failed', ['message_id' => 'a1']);

        $blocks = $this->projector->blocks();
        $this->assertCount(4, $blocks);
        for ($i = 0; $i < \count($blocks); ++$i) {
            $this->assertSame($i, $blocks[$i]->seq, "Block at index $i should have seq=$i");
        }
    }

    // ── Full scenarios ───────────────────────────────────────────────────────

    public function testFullToolLifecycle(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => '{"c',
        ]);
        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => 'md":"ls"}',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
            'arguments' => ['cmd' => 'ls'],
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01', 'delta' => 'file1.txt',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_01', 'delta' => "\nfile2.txt",
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_01', 'result' => "file1.txt\nfile2.txt",
            'duration_ms' => 150,
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        $callBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $callBlock->kind);
        $this->assertFalse($callBlock->streaming);
        $this->assertSame(['cmd' => 'ls'], $callBlock->meta['arguments']);

        $resultBlock = $blocks[1];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $resultBlock->kind);
        $this->assertFalse($resultBlock->streaming);
        $this->assertSame("file1.txt\nfile2.txt", $resultBlock->text);
        $this->assertSame(150, $resultBlock->meta['duration_ms']);
    }

    public function testFullHitlRoundTrip(): void
    {
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'text', 'prompt' => 'What is your name?',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('pending', $blocks[0]->meta['status']);

        $this->accept('human_input.answered', [
            'question_id' => 'q_01', 'answer' => 'Bob',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('answered', $blocks[0]->meta['status']);
    }

    public function testFullApprovalRoundTrip(): void
    {
        $this->accept('approval.requested', [
            'request_id' => 'appr_01', 'prompt' => 'Delete generated files?',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('pending', $blocks[0]->meta['status']);

        $this->accept('approval.approved', ['request_id' => 'appr_01']);

        $blocks = $this->projector->blocks();
        $this->assertSame('approved', $blocks[0]->meta['status']);
        $this->assertStringEndsWith('✓', $blocks[0]->text);
    }

    public function testMultipleToolCallsAreIndependent(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_02', 'tool_name' => 'write',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('tool_call_tc_01', $blocks[0]->id);
        $this->assertSame('tool_call_tc_02', $blocks[1]->id);

        $this->accept('tool_call.arguments_delta', [
            'tool_call_id' => 'tc_01', 'delta' => 'first_args',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertStringContainsString('first_args', $blocks[0]->text);
        $this->assertSame('write', $blocks[1]->text);
    }

    // ── Edge cases ───────────────────────────────────────────────────────────

    public function testDeltaBeforeStartIsSilentlyIgnored(): void
    {
        $this->accept('assistant.text_delta', [
            'block_id' => 'nonexistent', 'delta' => 'oops',
        ]);

        $this->assertSame([], $this->projector->blocks());
    }

    public function testCompletedBeforeStartIsSilentlyIgnored(): void
    {
        $this->accept('assistant.text_completed', [
            'block_id' => 'ghost', 'text' => 'phantom',
        ]);

        $this->assertSame([], $this->projector->blocks());
    }

    public function testMessageCompletedWithNoStreamingBlocksDoesNothing(): void
    {
        $this->accept('assistant.message_completed', ['message_id' => 'unknown']);

        $this->assertSame([], $this->projector->blocks());
    }

    public function testEmptyDeltaIsNoOp(): void
    {
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 'b1',
        ]);
        $before = $this->projector->blocks()[0];

        $this->accept('assistant.text_delta', [
            'block_id' => 'b1', 'delta' => '',
        ]);

        $after = $this->projector->blocks()[0];
        $this->assertSame($before->text, $after->text, 'Empty delta should not change block text');
    }

    public function testUnknownEventTypeIsIgnored(): void
    {
        $this->accept('run.started', []);
        $this->accept('progress.updated', ['message' => 'working...']);
        $this->accept('model.changed', ['model' => 'gpt-5']);

        $this->assertSame([], $this->projector->blocks(), 'Unknown/unhandled types should produce no blocks');
    }

    public function testReplayProducesSameBlocks(): void
    {
        $types = [
            ['type' => 'user.message_submitted', 'payload' => ['message_id' => 'u1', 'text' => 'Explain FP']],
            ['type' => 'assistant.message_started', 'payload' => ['message_id' => 'a1']],
            ['type' => 'assistant.thinking_started', 'payload' => ['message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0']],
            ['type' => 'assistant.thinking_delta', 'payload' => ['block_id' => 'a1_th0', 'delta' => 'FP is about']],
            ['type' => 'assistant.thinking_delta', 'payload' => ['block_id' => 'a1_th0', 'delta' => ' pure functions.']],
            ['type' => 'assistant.thinking_completed', 'payload' => ['block_id' => 'a1_th0', 'text' => 'FP is about pure functions.']],
            ['type' => 'assistant.text_started', 'payload' => ['message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'delta' => 'Functional']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'delta' => ' programming']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'delta' => ' uses immutable data.']],
            ['type' => 'assistant.text_completed', 'payload' => ['block_id' => 'a1_t0', 'text' => 'Functional programming uses immutable data.']],
            ['type' => 'assistant.message_completed', 'payload' => ['message_id' => 'a1']],
        ];

        // First pass
        foreach ($types as $e) {
            $this->projector->accept($this->event($e['type'], $e['payload']));
        }
        $first = $this->projector->blocks();

        // Reset and replay
        $this->projector->reset();
        foreach ($types as $e) {
            $this->projector->accept($this->event($e['type'], $e['payload']));
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

    public function testCancelledBlockSeqMatchesBlockIdSuffix(): void
    {
        // Regression test for the double-nextSeq() bug in cancellation

        // First add a block to advance seq to 1
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'Hi']);

        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('turn.cancelled', ['reason' => 'user_cancelled']);

        $blocks = $this->projector->blocks();
        // Blocks: user(seq=0), tool_call(seq=1), cancelled(seq=2)
        $this->assertCount(3, $blocks);

        $cancelBlock = $blocks[2];
        // ID should contain the seq number
        $this->assertStringContainsString((string) $cancelBlock->seq, $cancelBlock->id,
            'Cancelled block ID suffix must match its own seq number');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function accept(string $type, array $payload = [], string $runId = self::RUN_ID): void
    {
        $this->projector->accept($this->event($type, $payload, $runId));
    }

    /**
     * Accept an event without advancing the shared seq counter (for tests using different run IDs).
     *
     * @param array<string, mixed> $payload
     */
    private function acceptSameSeq(string $type, array $payload = [], string $runId = self::RUN_ID): void
    {
        $this->projector->accept($this->event($type, $payload, $runId));
    }

    /**
     * Build a RuntimeEvent-shaped array.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{type: string, runId: string, seq: int, payload: array<string, mixed>, v: int}
     */
    private function event(string $type, array $payload = [], string $runId = self::RUN_ID): array
    {
        return [
            'type' => $type,
            'runId' => $runId,
            'seq' => $this->seq++,
            'payload' => $payload,
            'v' => 1,
        ];
    }
}
