<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\SystemNoticeProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $dispatcher->addSubscriber(new RunLifecycleProjectionSubscriber());
        $dispatcher->addSubscriber(new SystemNoticeProjectionSubscriber());

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

    public function testRunStartedWithUserMessagesProjectsInitialPromptBlocks(): void
    {
        $this->accept('run.started', [
            'step_id' => 'start-1',
            'user_messages' => [
                ['message_id' => 'initial_run_1_0', 'text' => 'Write a README'],
            ],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
        $this->assertSame('initial_run_1_0', $blocks[0]->id);
        $this->assertSame('Write a README', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
    }

    public function testRunStartedWithMultipleUserMessages(): void
    {
        $this->accept('run.started', [
            'step_id' => 'start-1',
            'user_messages' => [
                ['message_id' => 'init_1', 'text' => 'First prompt'],
                ['message_id' => 'init_2', 'text' => 'Second prompt'],
            ],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('init_1', $blocks[0]->id);
        $this->assertSame('init_2', $blocks[1]->id);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[1]->kind);
    }

    public function testRunStartedWithoutUserMessagesCreatesNoUserBlocks(): void
    {
        $this->accept('run.started', [
            'step_id' => 'start-2',
        ]);

        // RunLifecycleProjectionSubscriber may add a System block for run start.
        $blocks = $this->projector->blocks();
        $kinds = array_map(static fn (TranscriptBlock $b) => $b->kind, $blocks);
        $this->assertNotContains(TranscriptBlockKindEnum::UserMessage, $kinds, 'No UserMessage blocks without user_messages payload');
    }

    // ── Assistant text stream ─────────────────────────────────────────────────

    public function testAssistantTextStreamCreatesAndAccumulatesBlock(): void
    {
        $this->accept('assistant.message_started', ['message_id' => 'a1']);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'Hello']);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => ', ']);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'world!']);

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
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'Hey']);
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
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'thinking' => 'Let me think...']);
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'thinking' => ' done.']);

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
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'thinking' => 'Hmm']);
        $this->accept('assistant.thinking_completed', [
            'block_id' => 'a1_th0', 'thinking' => 'Hmm, interesting.',
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
        $this->accept('assistant.thinking_delta', ['block_id' => 'a1_th0', 'thinking' => 'Analyzing...']);
        $this->accept('assistant.thinking_completed', [
            'block_id' => 'a1_th0', 'thinking' => 'Analyzing the request.',
        ]);
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0',
        ]);
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'The answer is 42.']);
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
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'Streaming...']);

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
        $this->accept('assistant.text_delta', ['block_id' => 'a1_t0', 'text' => 'Partial text...']);
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

    // ── Canonical message reconstruction (replay from events.jsonl) ──────────

    public function testMessageCompletedReconstructsThinkingFromCanonicalDetails(): void
    {
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_1',
            'text' => 'I will help you.',
            'details' => ['thinking' => 'Let me think about this carefully.'],
        ]);

        $blocks = $this->projector->blocks();
        // Expect: thinking block, then text block
        $this->assertCount(2, $blocks);

        $thinkBlock = $blocks[0];
        $this->assertSame('think_step_1', $thinkBlock->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $thinkBlock->kind);
        $this->assertSame('Let me think about this carefully.', $thinkBlock->text);
        $this->assertFalse($thinkBlock->streaming, 'Reconstructed thinking must be non-streaming');
        $this->assertTrue($thinkBlock->collapsed, 'Reconstructed thinking must be collapsed');
        $this->assertSame('step_1', $thinkBlock->meta['message_id']);

        $msgBlock = $blocks[1];
        $this->assertSame('msg_step_1', $msgBlock->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $msgBlock->kind);
        $this->assertSame('I will help you.', $msgBlock->text);
        $this->assertFalse($msgBlock->streaming);
    }

    public function testMessageCompletedReconstructsThinkingOnlyWhenTextEmpty(): void
    {
        // When the assistant only thinks (no text output), tool-call-only
        // turn: thinking should still be reconstructed.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_2',
            'text' => '',
            'details' => ['thinking' => 'I need to call the read tool.'],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Only the thinking block — no text block when text is empty');
        $this->assertSame('think_step_2', $blocks[0]->id);
        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('I need to call the read tool.', $blocks[0]->text);
    }

    public function testMessageCompletedReconstructsToolCallBlocksFromCanonicalToolCalls(): void
    {
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_3',
            'text' => '',
            'tool_calls' => [
                [
                    'id' => 'call_abc123',
                    'name' => 'read',
                    'arguments' => ['path' => '/tmp/file.txt'],
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $toolCallBlock = $blocks[0];
        $this->assertSame('tool_call_call_abc123', $toolCallBlock->id);
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $toolCallBlock->kind);
        $this->assertStringContainsString('read', $toolCallBlock->text);
        $this->assertStringContainsString('path: "/tmp/file.txt"', $toolCallBlock->text);
        $this->assertFalse($toolCallBlock->streaming, 'Reconstructed tool call must be non-streaming');
        $this->assertSame('call_abc123', $toolCallBlock->meta['tool_call_id']);
        $this->assertSame('read', $toolCallBlock->meta['tool_name']);
        $this->assertSame('step_3', $toolCallBlock->meta['message_id']);
    }

    public function testMessageCompletedSkipsToolCallReconstructionWhenBlockExists(): void
    {
        // Simulate live path: streaming tool-call block already created,
        // finalized, and has arguments.  Canonical reconstruction must
        // skip the duplicate.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'call_dup', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'call_dup',
            'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->assertCount(1, $this->projector->blocks());
        $existingText = $this->projector->blocks()[0]->text;

        // Now a canonical message_completed arrives with the same tool_call_id.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_dup',
            'text' => 'Done.',
            'tool_calls' => [
                ['id' => 'call_dup', 'name' => 'bash', 'arguments' => ['command' => 'ls']],
            ],
        ]);

        // ToolCall block must remain unchanged (no duplicate, no overwrite).
        $toolCallBlocks = array_values(array_filter(
            $this->projector->blocks(),
            static fn (TranscriptBlock $b) => TranscriptBlockKindEnum::ToolCall === $b->kind,
        ));
        $this->assertCount(1, $toolCallBlocks, 'Must not create duplicate tool-call block');
        $this->assertSame($existingText, $toolCallBlocks[0]->text);
    }

    public function testMessageCompletedSkipsThinkingReconstructionWhenBlockExists(): void
    {
        // Live streaming path creates a thinking block.
        $this->accept('assistant.thinking_started', [
            'message_id' => 'step_live', 'block_id' => 'think_live_0',
        ]);
        $this->accept('assistant.thinking_delta', [
            'block_id' => 'think_live_0', 'thinking' => 'Live thinking...',
        ]);
        $this->accept('assistant.thinking_completed', [
            'block_id' => 'think_live_0', 'thinking' => 'Live thinking done.',
        ]);

        // Canonical message_completed with details.thinking on live path
        // (which also has the streaming block).
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_live',
            'text' => 'Answer.',
            'details' => ['thinking' => 'Canonical thinking text.'],
        ]);

        // Must not create a duplicate 'think_step_live' block.
        $thinkingBlocks = array_values(array_filter(
            $this->projector->blocks(),
            static fn (TranscriptBlock $b) => TranscriptBlockKindEnum::AssistantThinking === $b->kind,
        ));
        $this->assertCount(1, $thinkingBlocks, 'Must not create duplicate thinking block');
        $this->assertSame('Live thinking done.', $thinkingBlocks[0]->text,
            'Existing streaming-originated thinking text must be preserved');
    }

    public function testMessageCompletedReconstructsBothThinkingAndToolCalls(): void
    {
        // Full canonical replay: thinking + text + tool-calls all in one event.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step_full',
            'text' => 'Let me read that file for you.',
            'details' => ['thinking' => 'The user wants me to read a file.'],
            'tool_calls' => [
                [
                    'id' => 'call_read_1',
                    'name' => 'read',
                    'arguments' => ['path' => '/tmp/doc.txt'],
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // Expected order reflecting live projection: thinking → text → tool-call
        $this->assertCount(3, $blocks);

        $this->assertSame(TranscriptBlockKindEnum::AssistantThinking, $blocks[0]->kind);
        $this->assertSame('think_step_full', $blocks[0]->id);

        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[1]->kind);
        $this->assertSame('msg_step_full', $blocks[1]->id);

        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[2]->kind);
        $this->assertSame('tool_call_call_read_1', $blocks[2]->id);
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
        // Finalized text should use the canonical formatted args, not
        // the raw streaming JSON deltas concatenated with formatted args.
        $this->assertSame('bash(cmd: "ls")', $block->text);
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

    public function testToolCallArgumentsCompletedEmptyArgsRemovesBlock(): void
    {
        // Streaming starts a tool-call block for a placeholder call with
        // empty arguments (common in parallel LLM responses where only one
        // tool call has valid parameters).
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_empty', 'tool_name' => 'bash',
        ]);
        $this->assertCount(1, $this->projector->blocks());

        // Empty arguments → suppress the block so the user does not see
        // a fake "bash()" entry that was never really executed.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_empty', 'tool_name' => 'bash',
            'arguments' => [],
        ]);

        $this->assertCount(0, $this->projector->blocks(),
            'Empty-argument tool calls must be suppressed');
    }

    public function testToolCallArgumentsCompletedEmptyArgsWithoutStartedDoesNotCreateBlock(): void
    {
        // Direct arguments_completed with empty args (no prior streaming)
        // must not create a ToolCall block.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_never_started', 'tool_name' => 'read',
            'arguments' => [],
        ]);

        $this->assertCount(0, $this->projector->blocks(),
            'Empty-argument completed must not create a block');
    }

    public function testMultipleParallelToolCallsOnlyNonNullArgsRemain(): void
    {
        // Simulate a parallel LLM response: 3 tool calls, only one has
        // real arguments.  The two placeholder calls must be suppressed.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_bash', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_read_empty', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_read_valid', 'tool_name' => 'read',
        ]);

        $this->assertCount(3, $this->projector->blocks());

        // Empty parallel call → suppressed.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_read_empty', 'tool_name' => 'read',
            'arguments' => [],
        ]);
        $this->assertCount(2, $this->projector->blocks());

        // Valid bash call.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_bash', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->assertCount(2, $this->projector->blocks());

        // Valid read call.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_read_valid', 'tool_name' => 'read',
            'arguments' => ['path' => '/tmp/x'],
        ]);
        $this->assertCount(2, $this->projector->blocks());

        // The remaining blocks should be the two valid calls, ordered
        // by their original tool_call.started sequence.
        $blocks = $this->projector->blocks();
        $this->assertSame('tool_call_tc_bash', $blocks[0]->id);
        $this->assertSame('bash(command: "ls")', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
        $this->assertSame('tool_call_tc_read_valid', $blocks[1]->id);
        $this->assertSame('read(path: "/tmp/x")', $blocks[1]->text);
        $this->assertFalse($blocks[1]->streaming);
    }

    // ── Orphan cleanup ───────────────────────────────────────────────────────

    public function testTurnStartedRemovesOrphanedToolCallBlocks(): void
    {
        // Simulate a parallel LLM response: three ToolCall blocks but only
        // one was actually executed (has a matching ToolResult).
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_a', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_a', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_b', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_b', 'tool_name' => 'read',
            'arguments' => ['path' => '/tmp/x'],
        ]);

        // Only tc_a was executed — tc_b is an orphan (the LLM emitted it
        // but the runtime rejected/ignored it).
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_a', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_a', 'result' => 'file.txt',
        ]);

        // Before TurnStarted, both ToolCall blocks still exist.
        $this->assertCount(3, $this->projector->blocks());

        // TurnStarted triggers orphan cleanup.
        $this->accept('turn.started', ['turn_no' => 2]);

        // Only the executed ToolCall and its ToolResult remain.
        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks,
            'Orphaned ToolCall block without matching ToolResult must be removed');
        $this->assertSame('tool_call_tc_a', $blocks[0]->id);
        $this->assertSame('tool_result_tc_a', $blocks[1]->id);
    }

    public function testTurnStartedKeepsMultipleExecutedToolCalls(): void
    {
        // Two tool calls, both executed — neither is orphaned.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_1', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_1', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_2', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_2', 'tool_name' => 'read',
            'arguments' => ['path' => '/tmp/x'],
        ]);

        // Both executed.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_1', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_1', 'result' => 'ok',
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_2', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_2', 'result' => 'data',
        ]);

        $this->assertCount(4, $this->projector->blocks());

        $this->accept('turn.started', ['turn_no' => 2]);

        $blocks = $this->projector->blocks();
        $this->assertCount(4, $blocks,
            'Both executed ToolCall blocks plus their ToolResults must remain');
    }

    public function testOrphanCleanupDoesNotRemoveToolCallWhenNoToolResultsExist(): void
    {
        // ToolCall exists but no ToolResult yet (mid-stream) — must not
        // remove the ToolCall block prematurely.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_streaming', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_streaming', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);

        // No tool_execution events yet — no ToolResult blocks.
        $this->accept('turn.started', ['turn_no' => 1]);

        // ToolCall block must survive because no ToolResult exists to
        // drive the orphan check.
        $this->assertCount(1, $this->projector->blocks());
        $this->assertSame('tool_call_tc_streaming', $this->projector->blocks()[0]->id);
    }

    public function testRunCompletedRemovesOrphanedToolCalls(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_orphan', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_orphan', 'tool_name' => 'read',
            'arguments' => ['path' => '/tmp/x'],
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_valid', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_valid', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_valid', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_valid', 'result' => 'done',
        ]);

        $this->accept('run.completed', ['reason' => 'completed']);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks,
            'RunCompleted must remove orphaned ToolCall block');
        $this->assertSame('tool_call_tc_valid', $blocks[0]->id);
        $this->assertSame('tool_result_tc_valid', $blocks[1]->id);
    }

    public function testToolExecutionStartedRemovesPhantomStreamingToolCallBlocks(): void
    {
        // Reproduces the exact user-visible phantom:
        //   ● read...          ← ToolCallStart emitted, never completed
        //   ● bash(command: …) ← ToolCallStart emitted AND completed
        // After tool_execution.started for bash, the phantom «read...»
        // must be removed — it's a streaming placeholder the LLM never
        // finalized in ToolCallComplete.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_read', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_bash', 'tool_name' => 'bash',
        ]);

        // Only bash was completed by the LLM.
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_bash', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls -la'],
        ]);

        // Before execution starts, both blocks exist (read is still streaming).
        $this->assertCount(2, $this->projector->blocks());
        $blocks = $this->projector->blocks();
        $this->assertSame('tool_call_tc_read', $blocks[0]->id);
        $this->assertTrue($blocks[0]->streaming, 'read block must be streaming — never completed');
        $this->assertSame('tool_call_tc_bash', $blocks[1]->id);
        $this->assertFalse($blocks[1]->streaming, 'bash block must be finalized');

        // Tool execution starts for bash → phantom read block must be removed.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_bash', 'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks,
            'Phantom streaming read block must be removed; only finalized bash + ToolResult remain');
        $this->assertSame('tool_call_tc_bash', $blocks[0]->id,
            'Only the finalized bash ToolCall block must survive');
        $this->assertSame('tool_result_tc_bash', $blocks[1]->id,
            'ToolResult block for bash must exist');
        $this->assertSame('bash(command: "ls -la")', $blocks[0]->text);
    }

    public function testToolExecutionStartedDoesNotRemoveFinalizedParallelToolCalls(): void
    {
        // Two parallel tool calls, both finalized, only one has started
        // executing yet.  The second finalized block must NOT be removed
        // — it's a legitimate pending tool call, not a phantom.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_first', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_first', 'tool_name' => 'bash',
            'arguments' => ['command' => 'ls'],
        ]);
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_second', 'tool_name' => 'read',
        ]);
        $this->accept('tool_call.arguments_completed', [
            'tool_call_id' => 'tc_second', 'tool_name' => 'read',
            'arguments' => ['path' => '/tmp/x'],
        ]);

        // First tool starts executing → only streaming phantoms are
        // removed; finalized tc_second must survive.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_first', 'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(3, $blocks,
            'Both finalized ToolCall blocks + tc_first ToolResult must remain');
        $this->assertSame('tool_call_tc_first', $blocks[0]->id);
        $this->assertSame('tool_call_tc_second', $blocks[1]->id);
        $this->assertSame('tool_result_tc_first', $blocks[2]->id);
    }

    public function testToolExecutionStartedDoesNotRemoveStreamingWhenNoFinalizedCallExists(): void
    {
        // Mid-stream: a ToolCall was started but not yet completed by
        // any ToolCallArgumentsCompleted.  No finalized call exists, so
        // the streaming block must survive — it's not a phantom, just
        // an in-progress stream.
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_stream', 'tool_name' => 'bash',
        ]);

        $this->assertTrue($this->projector->blocks()[0]->streaming);

        // tool_execution.started for a different hypothetical tool —
        // but no ToolCall block is finalized yet, so the guard prevents
        // removal.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_other', 'tool_name' => 'read',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks,
            'Streaming ToolCall must survive when no finalized call exists');
        $this->assertSame('tool_call_tc_stream', $blocks[0]->id);
        $this->assertTrue($blocks[0]->streaming);
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

    public function testToolExecutionCompletedWithoutResultReplacesRunningWithToolName(): void
    {
        // Replay scenario: tool_execution_start creates "Running…", then
        // tool_execution_end arrives without result (canonical events
        // often lack result text).  The block must not stay at "Running…".
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_replay', 'tool_name' => 'read',
        ]);
        $this->assertSame('Running…', $this->projector->blocks()[0]->text);

        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_replay',
            // No 'result' key — canonical events.jsonl often omit it.
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertFalse($block->streaming);
        $this->assertStringContainsString('read', $block->text,
            'Must fall back to tool name when result is empty and text was Running…');
        $this->assertStringNotContainsString('Running…', $block->text,
            'Must NOT leave Running… as final text');
    }

    public function testToolExecutionCompletedWithResultPreservesResult(): void
    {
        // Live path or replay-with-result: actual result text must survive.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_result', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_result', 'result' => 'file1.txt  file2.txt',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('file1.txt  file2.txt', $block->text,
            'Explicit result must be preserved, not replaced by tool name');
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

    // ── Output cap notice (ToolResult with exact model-facing text) ──────────

    public function testToolExecutionCompletedWithOutputCapAddsMetadataToToolResult(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_capped', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_capped',
            'result' => "[Output capped to 20000 characters]\n\nFull output: 50000 characters (~12500 tokens).\nSaved for audit at: /tmp/cap.txt\nDo NOT rerun...",
            'output_capped' => true,
            'output_cap_limit' => 20000,
            'output_cap_char_count' => 50000,
            'output_cap_saved_path' => '/tmp/cap.txt',
        ]);

        $blocks = $this->projector->blocks();
        // Should be only 1 block: ToolResult with cap metadata (no extra System block).
        $this->assertCount(1, $blocks);

        $toolBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $toolBlock->kind);
        $this->assertSame('tool_result_tc_capped', $toolBlock->id);
        // Exact model-facing cap notice text is preserved verbatim.
        $this->assertStringContainsString('Output capped to', $toolBlock->text);
        $this->assertSame('output_cap', $toolBlock->meta['notice_type']);
        $this->assertSame(20000, $toolBlock->meta['output_cap_limit']);
        $this->assertSame(50000, $toolBlock->meta['output_cap_char_count']);
        $this->assertSame('/tmp/cap.txt', $toolBlock->meta['output_cap_saved_path']);
        $this->assertFalse($toolBlock->streaming);

        // No paraphrase or extra System block.
        $this->assertStringNotContainsString('Output exceeded', $toolBlock->text);
        $this->assertStringNotContainsString('Model was shown', $toolBlock->text);
        $this->assertStringNotContainsString('visible chars', $toolBlock->text);
    }

    public function testToolExecutionCompletedWithoutOutputCapDoesNotAddCapMetadata(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_normal', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_normal',
            'result' => 'normal output without cap',
        ]);

        // Only one ToolResult block — no extra System block, no output-cap metadata.
        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $this->assertArrayNotHasKey('notice_type', $blocks[0]->meta);
    }

    public function testToolExecutionFailedWithOutputCapAddsMetadataToToolResult(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_failcap', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.failed', [
            'tool_call_id' => 'tc_failcap',
            'result' => "[Output capped to 5000 characters]\n\nFull output: 6000 characters.\nSaved for audit at: /tmp/err.txt",
            'output_capped' => true,
            'output_cap_limit' => 5000,
            'output_cap_char_count' => 6000,
            'output_cap_saved_path' => '/tmp/err.txt',
            'is_error' => true,
        ]);

        $blocks = $this->projector->blocks();
        // Only 1 block: failed ToolResult with cap metadata.
        $this->assertCount(1, $blocks);

        $toolBlock = $blocks[0];
        $this->assertTrue($toolBlock->meta['is_error']);
        $this->assertSame('output_cap', $toolBlock->meta['notice_type']);
        $this->assertSame(5000, $toolBlock->meta['output_cap_limit']);
        $this->assertSame(6000, $toolBlock->meta['output_cap_char_count']);
        $this->assertSame('/tmp/err.txt', $toolBlock->meta['output_cap_saved_path']);

        // Exact model-facing text preserved.
        $this->assertStringContainsString('Output capped to', $toolBlock->text);
    }

    // ── Model tool input (central cap path) ─────────────────────────────────

    public function testModelInputMessagesUpdatesToolResultTextToExactModelFacingContent(): void
    {
        // Simulate a tool execution with FULL (uncapped) output — as if a
        // central cap was applied by OutputCapLlmTransformHook.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_central', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_central',
            'result' => 'Full file content that is very long...',
            // Note: no output_capped=true — this is the central-cap path
            // where raw output is stored and model-facing text is delivered
            // later via assistant.message_completed.model_input_messages.
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame('Full file content that is very long...', $blocks[0]->text);

        // Now the next LLM step completes with model_input_messages carrying
        // the exact capped text the model actually saw.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step-2',
            'text' => 'I see the file is too large.',
            'model_input_messages' => [
                [
                    'tool_call_id' => 'tc_central',
                    'tool_name' => 'read',
                    'text' => "[Output capped to 20000 characters]\n\nFull output: 35000 characters\nSaved for audit at: /tmp/cap.txt\n\nDo NOT rerun the same full command/tool call.\nDo NOT read the saved file in full.",
                    'metadata' => ['output_cap_limit' => 20000],
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: ToolResult (updated by model_input_messages) + AssistantMessage (from the event)
        $this->assertCount(2, $blocks, 'Expected ToolResult + AssistantMessage blocks');

        $toolBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $toolBlock->kind);
        $this->assertSame('tool_result_tc_central', $toolBlock->id);

        // Text must now be the exact model-facing capped notice, not raw output.
        $this->assertStringContainsString('[Output capped to 20000 characters]', $toolBlock->text);
        $this->assertStringNotContainsString('Full file content that is very long', $toolBlock->text,
            'ToolResult must NOT contain raw output after model_input update');

        // Cap metadata from the model tool input must be set.
        $this->assertSame('output_cap', $toolBlock->meta['notice_type']);
        $this->assertSame(20000, $toolBlock->meta['output_cap_limit']);
        $this->assertTrue($toolBlock->meta['model_input_exact']);

        // No paraphrase.
        $this->assertStringNotContainsString('Output exceeded', $toolBlock->text);
        $this->assertStringNotContainsString('Model was shown', $toolBlock->text);
        $this->assertStringNotContainsString('visible chars', $toolBlock->text);
    }

    public function testModelInputMessagesWithNonMatchingToolCallIdIsIgnored(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_other', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_other',
            'result' => 'some output',
        ]);

        $this->accept('assistant.message_completed', [
            'message_id' => 'step-2',
            'text' => 'OK',
            'model_input_messages' => [
                [
                    'tool_call_id' => 'tc_nonexistent',
                    'tool_name' => 'read',
                    'text' => '[Output capped to 5000]',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: ToolResult + AssistantMessage (from the event)
        $this->assertCount(2, $blocks);
        // Existing ToolResult must be unchanged.
        $this->assertSame('some output', $blocks[0]->text);
        $this->assertArrayNotHasKey('model_input_exact', $blocks[0]->meta);
    }

    public function testModelInputMessagesWithoutToolCallIdIsIgnored(): void
    {
        $this->accept('assistant.message_completed', [
            'message_id' => 'step-2',
            'text' => 'OK',
            'model_input_messages' => [
                [
                    'tool_call_id' => '',
                    'tool_name' => 'unknown',
                    'text' => 'some message',
                ],
            ],
        ]);

        // No ToolResult blocks existed before.  Only AssistantMessage is created.
        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
    }

    public function testModelInputMessagesDeduplicatesByContentHash(): void
    {
        // Same tool result updated twice with same model-facing text.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_dedup', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_dedup',
            'result' => 'Very long raw output...',
        ]);

        // First update.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step-2',
            'text' => 'OK',
            'model_input_messages' => [
                [
                    'tool_call_id' => 'tc_dedup',
                    'tool_name' => 'read',
                    'text' => '[Output capped to 10000]',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks, 'Expected ToolResult + AssistantMessage after first model input');
        $this->assertStringContainsString('[Output capped to 10000]', $blocks[0]->text);
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);

        // Second update with same text — should be deduplicated.
        $this->accept('assistant.message_completed', [
            'message_id' => 'step-3',
            'text' => 'OK',
            'model_input_messages' => [
                [
                    'tool_call_id' => 'tc_dedup',
                    'tool_name' => 'read',
                    'text' => '[Output capped to 10000]',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 3 blocks: ToolResult + AssistantMessage-1 + AssistantMessage-2 (deduped model_input_messages,
        // but each assistant.message_completed still creates an AssistantMessage block).
        $this->assertCount(3, $blocks, 'Expected ToolResult + 2x AssistantMessage after second model input');
        // First block is ToolResult, unchanged by dedup.
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $this->assertStringContainsString('[Output capped to 10000]', $blocks[0]->text);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[1]->kind);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[2]->kind);
    }

    // ── Generated user-role model input (System block) ──────────────────────

    public function testGeneratedUserModelInputCreatesSystemBlockWithExactText(): void
    {
        $this->accept('assistant.message_completed', [
            'message_id' => 'step-img-1',
            'text' => 'Here is the screenshot.',
            'model_input_messages' => [
                [
                    'role' => 'user',
                    'text' => 'Tool result image for view_image: /tmp/screenshot.png (image/png, 1920x1080, 245760 bytes)',
                    'source' => 'tool_result_image',
                    'tool_call_id' => 'call_view_001',
                    'tool_name' => 'view_image',
                    'metadata' => ['model_input_generated' => true, 'has_non_text_content' => true],
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: AssistantMessage + System (user-role model input)
        $this->assertCount(2, $blocks, 'Expected AssistantMessage + System blocks');

        // AssistantStreamProjectionSubscriber runs first, so AssistantMessage is blocks[0].
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        $systemBlock = $blocks[1];
        $this->assertSame(TranscriptBlockKindEnum::System, $systemBlock->kind);
        $this->assertSame('generated_input_call_view_001', $systemBlock->id);
        $this->assertSame('Tool result image for view_image: /tmp/screenshot.png (image/png, 1920x1080, 245760 bytes)', $systemBlock->text);
        $this->assertTrue($systemBlock->meta['model_input_exact']);
        $this->assertSame('user', $systemBlock->meta['model_input_role']);
        $this->assertFalse($systemBlock->streaming);

        // No paraphrase.
        $this->assertStringNotContainsString('Model was shown', $systemBlock->text);
        $this->assertStringNotContainsString('Output exceeded', $systemBlock->text);
        $this->assertStringNotContainsString('visible chars', $systemBlock->text);
    }

    public function testGeneratedUserModelInputDeduplicatesByBlockId(): void
    {
        // Same generated user input delivered twice produces one System block.
        $payload = [
            'role' => 'user',
            'text' => 'Tool result image for view_image: /tmp/screenshot.png',
            'source' => 'tool_result_image',
            'tool_call_id' => 'call_view_002',
            'tool_name' => 'view_image',
            'metadata' => ['model_input_generated' => true],
        ];

        $this->accept('assistant.message_completed', [
            'message_id' => 'step-1',
            'text' => 'First response',
            'model_input_messages' => [$payload],
        ]);

        $this->accept('assistant.message_completed', [
            'message_id' => 'step-2',
            'text' => 'Second response',
            'model_input_messages' => [$payload],
        ]);

        $blocks = $this->projector->blocks();
        // 3 blocks: AssistantMessage-1 + System + AssistantMessage-2 (System deduped, in insertion order)
        $this->assertCount(3, $blocks, 'Expected 2x AssistantMessage + System (no duplicate System block)');

        // System block is at index 1 (after first AssistantMessage, before second).
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[1]->kind);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[2]->kind);

        // System block is at index 1, unchanged.
        $this->assertSame('Tool result image for view_image: /tmp/screenshot.png', $blocks[1]->text);
        $this->assertSame('generated_input_call_view_002', $blocks[1]->id);
        $this->assertTrue($blocks[1]->meta['model_input_exact']);
        $this->assertSame('user', $blocks[1]->meta['model_input_role']);
        $this->assertFalse($blocks[1]->streaming);
    }

    public function testGeneratedUserModelInputWithoutToolCallIdDeduplicatesByHash(): void
    {
        $payload = [
            'role' => 'user',
            'text' => 'Screenshot image from view_image',
            'source' => 'tool_result_image',
            'tool_call_id' => '',
            'metadata' => ['model_input_generated' => true],
        ];

        $this->accept('assistant.message_completed', [
            'message_id' => 'step-1',
            'text' => 'OK',
            'model_input_messages' => [$payload],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: AssistantMessage + System
        $this->assertCount(2, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[0]->kind);
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[1]->kind);
        $this->assertStringContainsString('generated_input', $blocks[1]->id);
    }

    public function testModelInputOnAssistantMessageFailedProjectsToolText(): void
    {
        // Failed LLM step with model_input_messages should still update
        // the ToolResult block with exact model-facing text.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_fail', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_fail',
            'result' => 'Raw full file content from before failure',
        ]);

        $this->accept('assistant.message_failed', [
            'message_id' => 'step-fail-1',
            'text' => 'Provider error: timeout',
            'model_input_messages' => [
                [
                    'role' => 'tool',
                    'tool_call_id' => 'tc_fail',
                    'tool_name' => 'read',
                    'text' => '[Output capped to 20000 characters] — model saw this before error',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: ToolResult (updated) + Error (from assistant.message_failed)
        $this->assertCount(2, $blocks, 'Expected ToolResult + Error block after failed message with model inputs');

        $toolBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $toolBlock->kind);
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[1]->kind, 'Failed message creates Error block, not AssistantMessage');
        $this->assertStringContainsString('[Output capped to 20000 characters]', $toolBlock->text);
        $this->assertStringNotContainsString('Raw full file content', $toolBlock->text,
            'ToolResult must NOT contain raw output after model_input update on failed path');
        $this->assertTrue($toolBlock->meta['model_input_exact']);
    }

    public function testModelInputOnTurnCancelledProjectsToolText(): void
    {
        // Aborted LLM step with model_input_messages should update
        // the ToolResult block with exact text.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_cancel', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_cancel',
            'result' => 'Long bash output that was capped',
        ]);

        $this->accept('turn.cancelled', [
            'reason' => 'user_cancelled',
            'model_input_messages' => [
                [
                    'role' => 'tool',
                    'tool_call_id' => 'tc_cancel',
                    'tool_name' => 'bash',
                    'text' => '[Output capped to 5000 characters]',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: ToolResult (updated) + Cancelled (from CancellationProjectionSubscriber)
        $this->assertCount(2, $blocks, 'Expected ToolResult + Cancelled block after cancellation with model inputs');

        $toolBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $toolBlock->kind);
        $this->assertStringContainsString('[Output capped to 5000 characters]', $toolBlock->text);
        $this->assertTrue($toolBlock->meta['model_input_exact']);
    }

    public function testSafeGuardLikeToolDenialShowsExactTextInToolResult(): void
    {
        // Simulate a SafeGuard denial that replaces the tool result with
        // a model-facing denial message.  The TUI must show this exact
        // JSON/tool content, not 'completed' and not a paraphrase.
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_deny', 'tool_name' => 'sudo',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_deny',
            'result' => 'sudo completed',
        ]);

        $this->accept('assistant.message_completed', [
            'message_id' => 'step-deny-1',
            'text' => 'I cannot run that command.',
            'model_input_messages' => [
                [
                    'role' => 'tool',
                    'tool_call_id' => 'tc_deny',
                    'tool_name' => 'sudo',
                    'text' => '{"error":"HardBlock: sudo is not allowed","safeguard":true,"category":"HardBlock","tool_call_id":"tc_deny"}',
                    'source' => 'tool_result',
                ],
            ],
        ]);

        $blocks = $this->projector->blocks();
        // 2 blocks: ToolResult (exact JSON, created by tool_execution) + AssistantMessage
        $this->assertCount(2, $blocks, 'Expected ToolResult + AssistantMessage after denial model input');

        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $toolBlock = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $toolBlock->kind);
        // Text must be the exact JSON the model saw.
        $this->assertSame('{"error":"HardBlock: sudo is not allowed","safeguard":true,"category":"HardBlock","tool_call_id":"tc_deny"}', $toolBlock->text);
        $this->assertTrue($toolBlock->meta['model_input_exact']);
        $this->assertArrayNotHasKey('notice_type', $toolBlock->meta);
        $this->assertSame(TranscriptBlockKindEnum::AssistantMessage, $blocks[1]->kind);

        // No paraphrase.
        $this->assertStringNotContainsString('sudo completed', $toolBlock->text);
        $this->assertStringNotContainsString('Model was shown', $toolBlock->text);
        $this->assertStringNotContainsString('Output exceeded', $toolBlock->text);
        $this->assertStringNotContainsString('visible chars', $toolBlock->text);
    }

    // ── System notice ───────────────────────────────────────────────────────

    public function testSystemNoticeCreatesSystemBlock(): void
    {
        $this->accept('system.notice', [
            'source' => 'ext_test_notification',
            'severity' => 'info',
            'text' => 'Extension message: processing complete',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::System, $block->kind);
        $this->assertSame('Extension message: processing complete', $block->text);
        $this->assertSame('ext_test_notification', $block->meta['source']);
        $this->assertSame('info', $block->meta['severity']);
        $this->assertFalse($block->streaming);
    }

    public function testSystemNoticeDeduplicatesById(): void
    {
        // Two identical system notices produce one block (dedup by block ID).
        $this->accept('system.notice', [
            'source' => 'ext_dup',
            'text' => 'Duplicate notice',
        ]);
        $this->accept('system.notice', [
            'source' => 'ext_dup',
            'text' => 'Duplicate notice',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Duplicate system.notice events must deduplicate by block ID');
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

    public function testTurnCancelledRemovesStreamingBlocksAndCreatesCancelledBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'bash',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertTrue($blocks[0]->streaming, 'ToolCall block should be streaming');
        $this->assertTrue($blocks[1]->streaming, 'ToolResult block should be streaming');

        $this->accept('turn.cancelled', ['reason' => 'user_cancelled']);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Only the cancellation block should remain; streaming blocks must be removed');
        $this->assertSame(TranscriptBlockKindEnum::Cancelled, $blocks[0]->kind);
        $this->assertStringContainsString('turn cancelled', $blocks[0]->text);
        $this->assertFalse($blocks[0]->streaming);
    }

    public function testRunCancelledRemovesStreamingBlocksAndCreatesCancelledBlock(): void
    {
        $this->accept('tool_call.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_01', 'tool_name' => 'read',
        ]);

        $this->accept('run.cancelled', ['reason' => 'provider_aborted']);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Only the cancellation block should remain; streaming blocks must be removed');

        $cancelBlock = $blocks[0];
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

    public function testTurnCancelledRemovesOnlySameRunBlocks(): void
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
        // run_1 streaming block is removed; cancellation block added.
        // run_other block remains streaming, still at its original position.
        $this->assertCount(2, $blocks, 'Cancellation block for run_1 + run_other streaming block');
        $this->assertSame(TranscriptBlockKindEnum::ToolCall, $blocks[0]->kind, 'Other-run block keeps original position');
        $this->assertSame('run_other', $blocks[0]->runId, 'Other-run block must survive');
        $this->assertTrue($blocks[0]->streaming, 'Other-run block should remain streaming');
        $this->assertSame(TranscriptBlockKindEnum::Cancelled, $blocks[1]->kind, 'Cancellation block appended at end');
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

    // ── Edge cases (ignored / no-op events) ──────────────────────────────────

    #[DataProvider('noOpEventProvider')]
    public function testNoOpEventsProduceNoBlocks(string $type, array $payload): void
    {
        // Some no-op variants need a pre-existing block to prove delta/completed
        // don't affect blocks they don't own.
        if ('assistant.text_delta' === $type && 'oops' === ($payload['delta'] ?? '')) {
            $this->accept($type, $payload);
            $this->assertSame([], $this->projector->blocks());
            return;
        }
        if ('assistant.text_completed' === $type) {
            $this->accept($type, $payload);
            $this->assertSame([], $this->projector->blocks());
            return;
        }

        $this->accept($type, $payload);
        $this->assertSame([], $this->projector->blocks(), "{$type} should produce no blocks");
    }

    /** @return iterable<string, array{string, array<string,mixed>}> */
    public static function noOpEventProvider(): iterable
    {
        yield 'delta before start' => ['assistant.text_delta', ['block_id' => 'nonexistent', 'delta' => 'oops']];
        yield 'completed before start' => ['assistant.text_completed', ['block_id' => 'ghost', 'text' => 'phantom']];
        yield 'message completed, no blocks' => ['assistant.message_completed', ['message_id' => 'unknown']];
        yield 'progress.updated' => ['progress.updated', ['message' => 'working...']];
        yield 'model.changed' => ['model.changed', ['model' => 'gpt-5']];
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

    public function testReplayProducesSameBlocks(): void
    {
        $types = [
            ['type' => 'user.message_submitted', 'payload' => ['message_id' => 'u1', 'text' => 'Explain FP']],
            ['type' => 'assistant.message_started', 'payload' => ['message_id' => 'a1']],
            ['type' => 'assistant.thinking_started', 'payload' => ['message_id' => 'a1', 'content_index' => 0, 'block_id' => 'a1_th0']],
            ['type' => 'assistant.thinking_delta', 'payload' => ['block_id' => 'a1_th0', 'thinking' => 'FP is about']],
            ['type' => 'assistant.thinking_delta', 'payload' => ['block_id' => 'a1_th0', 'thinking' => ' pure functions.']],
            ['type' => 'assistant.thinking_completed', 'payload' => ['block_id' => 'a1_th0', 'text' => 'FP is about pure functions.']],
            ['type' => 'assistant.text_started', 'payload' => ['message_id' => 'a1', 'content_index' => 1, 'block_id' => 'a1_t0']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'text' => 'Functional']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'text' => ' programming']],
            ['type' => 'assistant.text_delta', 'payload' => ['block_id' => 'a1_t0', 'text' => ' uses immutable data.']],
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
        // Streaming tool_call is removed by turn.cancelled; only user + cancel remain.
        $this->assertCount(2, $blocks);

        $cancelBlock = $blocks[1];
        // ID should contain the seq number
        $this->assertStringContainsString((string) $cancelBlock->seq, $cancelBlock->id,
            'Cancelled block ID suffix must match its own seq number');
    }

    // ── Run lifecycle ───────────────────────────────────────────────────────

    public function testRunFailedCreatesErrorBlock(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'hi']);
        $this->accept('run.failed', [
            'reason' => 'failed',
            'error' => 'CAS conflict exhausted after 3 attempts',
            'message_type' => 'StartRun',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        $errorBlock = $blocks[1];
        $this->assertSame(TranscriptBlockKindEnum::Error, $errorBlock->kind);
        $this->assertStringContainsString('Run failed', $errorBlock->text);
        $this->assertStringContainsString('CAS conflict exhausted', $errorBlock->text);
        $this->assertSame('CAS conflict exhausted after 3 attempts', $errorBlock->meta['error']);
    }

    public function testRunFailedRemovesStreamingBlocks(): void
    {
        $this->accept('assistant.text_started', [
            'message_id' => 'a1', 'block_id' => 'b1',
        ]);
        $this->accept('assistant.text_delta', [
            'message_id' => 'a1', 'block_id' => 'b1', 'index' => 0, 'text' => 'partial...',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Should have one streaming block before run.failed');
        $this->assertTrue($blocks[0]->streaming);

        $this->accept('run.failed', [
            'reason' => 'failed',
            'error' => 'Permanent worker failure',
            'message_type' => 'AdvanceRun',
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks, 'Only the error block should remain; streaming block must be removed');
        $this->assertSame(TranscriptBlockKindEnum::Error, $blocks[0]->kind);
    }

    public function testRunCompletedCreatesNoBlock(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'hi']);
        $this->accept('run.completed', ['reason' => 'completed']);

        $blocks = $this->projector->blocks();
        // Only the user message block; run.completed creates none.
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::UserMessage, $blocks[0]->kind);
    }

    public function testRunCompletedDoesNotClobberExistingBlocks(): void
    {
        $this->accept('user.message_submitted', ['message_id' => 'u1', 'text' => 'hi']);
        $this->accept('assistant.text_started', ['message_id' => 'a1', 'block_id' => 'b1']);
        $this->accept('assistant.text_delta', ['message_id' => 'a1', 'block_id' => 'b1', 'index' => 0, 'text' => 'hello']);
        $this->accept('assistant.text_completed', ['message_id' => 'a1', 'block_id' => 'b1']);

        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks, 'User + finalized assistant block');

        $this->accept('run.completed', ['reason' => 'completed']);

        $blocksAfter = $this->projector->blocks();
        $this->assertCount(2, $blocksAfter, 'No extra blocks after run.completed');
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
