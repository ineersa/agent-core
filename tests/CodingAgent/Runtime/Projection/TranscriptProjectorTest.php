<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Projection;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\AssistantStreamProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\CancellationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\HitlProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ModelNotificationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\RunLifecycleProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\UserMessageProjectionSubscriber;
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
        $dispatcher->addSubscriber(new ModelNotificationProjectionSubscriber());

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

    public function testQueuedUserMessageDoesNotProjectBlock(): void
    {
        $this->accept('user.message_queued', [
            'text' => 'STEER_QUEUED_MARKER',
            'idempotency_key' => 'ik-no-block',
        ]);

        $this->assertCount(0, $this->projector->blocks(), 'Queued user messages must not pollute the transcript (rendered in the TUI widget instead)');
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
        $this->assertFalse($blocks[0]->collapsed);
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

    public function testThinkingBlockDoesNotEncodeDisplayCollapseByDefault(): void
    {
        // Projection should not encode display collapse policy.  Display collapse
        // is a local rendering concern owned by TranscriptDisplayConfig, not the
        // canonical projection DTO.
        $this->accept('assistant.thinking_started', [
            'message_id' => 'a1', 'block_id' => 'th1',
        ]);

        $this->assertFalse($this->projector->blocks()[0]->collapsed);
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
        $this->assertFalse($thinkBlock->collapsed, 'Reconstructed thinking must NOT encode display collapse');
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

    public function testToolExecutionProgressThenFinalResultShowsHandoff(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_sub', 'tool_name' => 'subagent',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_sub',
            'tool_name' => 'subagent',
            'subagent_progress' => [
                'mode' => 'single',
                'status' => 'running',
                'agent' => 'scout',
                'task_preview' => 'Inspect resume path',
            ],
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_sub',
            'result' => 'HANDOFF: resume uses shared applier',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertStringContainsString('HANDOFF: resume uses shared applier', $block->text);
        $this->assertFalse($block->streaming);
        $this->assertTrue($block->meta['subagent_final'] ?? false);
        $this->assertIsArray($block->meta['subagent_progress'] ?? null);
    }

    public function testToolExecutionEmptyResultPreservesProgressText(): void
    {
        $this->accept('tool_execution.started', [
            'tool_call_id' => 'tc_legacy', 'tool_name' => 'agent_retrieve',
        ]);
        $this->accept('tool_execution.output_delta', [
            'tool_call_id' => 'tc_legacy',
            'delta' => 'partial output line',
        ]);
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'tc_legacy',
        ]);

        $block = $this->projector->blocks()[0];
        $this->assertSame('partial output line', $block->text);
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

    public function testToolQuestionRequestedDoesNotCreateTranscriptBlock(): void
    {
        // Local TUI questions (e.g. BashTool background prompts) flow as
        // tool_question.requested. The projector must never turn them into
        // transcript blocks — only human_input.* / approval.* (HITL) create blocks.
        $this->accept('tool_question.requested', [
            'question_id' => 'tq_01',
            'kind' => 'confirm',
            'prompt' => 'Keep waiting?',
            'schema' => ['type' => 'boolean'],
        ]);

        self::assertCount(0, $this->projector->blocks(),
            'tool_question.requested must not create transcript blocks');
    }

    public function testHumanInputRequestedUsesUiKindForMetaKind(): void
    {
        // The real factory emits kind='interrupt' (transport marker) with UI
        // semantics in ui_kind. The subscriber must prefer ui_kind when present
        // so the transcript block's meta['kind'] carries the semantic kind, not
        // the transport-level marker.
        $this->accept('human_input.requested', [
            'request_id' => 'req_01', 'question_id' => 'q_01',
            'kind' => 'interrupt',
            'ui_kind' => 'approval',
            'prompt' => 'Approve deployment?',
            'schema' => ['type' => 'boolean'],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $block = $blocks[0];
        $this->assertSame('hitl_q_01', $block->id);
        $this->assertSame(TranscriptBlockKindEnum::Question, $block->kind);
        $this->assertSame('Approve deployment?', $block->text);
        // meta['kind'] must be 'approval' (from ui_kind), NOT 'interrupt' (transport kind)
        $this->assertSame('approval', $block->meta['kind'],
            'meta kind must use ui_kind when both kind and ui_kind are present');
    }

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

    // ── Model notification ─────────────────────────────────────────────────

    /**
     * Test thesis: a generic model.notification runtime event projects a System
     * block with exact notification text, severity, source, and kind metadata —
     * no output-cap-specific detection or text parsing.
     */
    public function testModelNotificationProjectsSystemBlockWithExactText(): void
    {
        $this->accept('model.notification', [
            'id' => 'notif-1',
            'source' => 'output_cap',
            'kind' => 'output_capped',
            'severity' => 'warning',
            'delivery' => 'tool_result_replace',
            'text' => "[Output capped: 5000 chars (~1250 tokens) > 100-char cap]\nSaved full output: /tmp/cap-123.txt",
            'tool_call_id' => 'call-1',
            'tool_name' => 'read',
            'metadata' => [
                'cap' => 100,
                'char_count' => 5000,
            ],
        ]);

        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);

        $block = $blocks[0];
        $this->assertSame(TranscriptBlockKindEnum::System, $block->kind);

        // Exact model-facing text is what the TUI shows — no paraphrase.
        $this->assertStringStartsWith('[Output capped:', $block->text);
        $this->assertStringContainsString('Saved full output', $block->text);

        // Severity drives TUI icon/color — no text-parsing from renderer.
        $this->assertSame('warning', $block->meta['severity']);
        $this->assertSame('output_cap', $block->meta['source']);
        $this->assertSame('output_capped', $block->meta['kind']);
        $this->assertSame('call-1', $block->meta['tool_call_id']);

        // Producer metadata is available for detail views.
        $this->assertIsArray($block->meta['producer_metadata']);
        $this->assertSame(100, $block->meta['producer_metadata']['cap']);
    }

    public function testModelNotificationWithToolResultReplaceCompactsToolResult(): void
    {
        // Set up a ToolResult block via tool_execution.completed.
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'call-compact',
            'tool_name' => 'read',
            'result' => 'Full raw output that should be replaced by the notification',
        ]);

        // Verify the ToolResult block has the original text.
        $blocks = $this->projector->blocks();
        $this->assertCount(1, $blocks);
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $this->assertSame('Full raw output that should be replaced by the notification', $blocks[0]->text);

        // Now emit a model_notification with delivery=tool_result_replace
        // targeting the same tool_call_id.
        $this->accept('model.notification', [
            'id' => 'notif-compact-1',
            'source' => 'output_cap',
            'kind' => 'output_capped',
            'severity' => 'warning',
            'delivery' => 'tool_result_replace',
            'text' => "[Output capped: 5000 chars (~1250 tokens) > 100-char cap]\nSaved full output: /tmp/cap-compact.txt",
            'tool_call_id' => 'call-compact',
            'tool_name' => 'read',
        ]);

        // Two blocks: the System notification and the compacted ToolResult.
        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        // Block 0: ToolResult (created first, compacted by notification).
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $this->assertSame('read completed', $blocks[0]->text);
        $this->assertTrue($blocks[0]->meta['compact_label'] ?? false, 'compact_label meta must be true');

        // Block 1: System notification with exact text.
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[1]->kind);
        $this->assertStringStartsWith('[Output capped:', $blocks[1]->text);
        $this->assertSame('warning', $blocks[1]->meta['severity']);
        $this->assertSame('output_cap', $blocks[1]->meta['source']);
        $this->assertSame('call-compact', $blocks[1]->meta['tool_call_id']);
    }

    public function testModelNotificationWithoutDeliveryDoesNotCompactToolResult(): void
    {
        // Set up a ToolResult block.
        $this->accept('tool_execution.completed', [
            'tool_call_id' => 'call-nocompact',
            'tool_name' => 'bash',
            'result' => 'Normal shell output that should remain visible',
        ]);

        // Emit a model_notification WITHOUT delivery=tool_result_replace.
        $this->accept('model.notification', [
            'id' => 'notif-info',
            'source' => 'extension',
            'kind' => 'nudge',
            'severity' => 'info',
            'delivery' => 'context_message',
            'text' => 'Free-standing informational nudge',
            'tool_call_id' => 'call-nocompact',
        ]);

        // Two blocks: ToolResult unchanged + System notification.
        $blocks = $this->projector->blocks();
        $this->assertCount(2, $blocks);

        // ToolResult must still have original text (not compacted).
        $this->assertSame(TranscriptBlockKindEnum::ToolResult, $blocks[0]->kind);
        $this->assertSame('Normal shell output that should remain visible', $blocks[0]->text);

        // System notification must be present.
        $this->assertSame(TranscriptBlockKindEnum::System, $blocks[1]->kind);
        $this->assertSame('Free-standing informational nudge', $blocks[1]->text);
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
