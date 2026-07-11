<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(RuntimeEventMapper::class)]
#[CoversClass(RuntimeEventTranslator::class)]
final class RuntimeEventMapperTest extends TestCase
{
    private RuntimeEventMapper $mapper;
    private string $runId = 'run-test-1';

    protected function setUp(): void
    {
        $this->mapper = new RuntimeEventMapper(
            new RuntimeEventTranslator(new EventDispatcher()),
        );
    }

    // ── Lifecycle normalization ──────────────────────────────────────────────

    public function testNormalizesRunStartedToRunStarted(): void
    {
        $event = $this->runEvent('run_started', ['step_id' => 'start-1']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $result->type);
        $this->assertSame('start-1', $result->payload['step_id']);
        $this->assertSame($this->runId, $result->runId);
        $this->assertSame(1, $result->seq);
    }

    public function testNormalizesRunStartedWithUserMessages(): void
    {
        $event = $this->runEvent('run_started', [
            'step_id' => 'start-2',
            'payload' => [
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System prompt']]],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Initial prompt']]],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunStarted->value, $result->type);
        $this->assertSame('start-2', $result->payload['step_id']);
        $this->assertArrayHasKey('user_messages', $result->payload);
        $this->assertCount(1, $result->payload['user_messages']);
        $this->assertSame('Initial prompt', $result->payload['user_messages'][0]['text']);
        // System/user-context messages are not included
        $texts = array_column($result->payload['user_messages'], 'text');
        $this->assertNotContains('System prompt', $texts, 'System messages must not appear in user_messages');
    }

    public function testNormalizesRunStartedWithoutUserMessages(): void
    {
        // When the normalized payload has no user-role messages.
        $event = $this->runEvent('run_started', [
            'step_id' => 'start-3',
            'payload' => [
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System prompt only']]],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame('start-3', $result->payload['step_id']);
        $this->assertArrayNotHasKey('user_messages', $result->payload);
    }

    public function testNormalizesRunStartedWithEmptyUserMessageSkipped(): void
    {
        // User-role messages with empty text should be skipped.
        $event = $this->runEvent('run_started', [
            'step_id' => 'start-4',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => []],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertArrayNotHasKey('user_messages', $result->payload);
    }

    public function testNormalizesTurnAdvancedToTurnStarted(): void
    {
        $event = $this->runEvent('turn_advanced', ['turn_no' => 3]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::TurnStarted->value, $result->type);
        $this->assertSame(3, $result->payload['turn_no']);
    }

    public function testNormalizesAgentEndCompletedToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'completed']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        $this->assertSame('completed', $result->payload['reason']);
    }

    public function testNormalizesToolExecutionEndCancelledToToolExecutionCancelled(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-cancel',
            'order_index' => 0,
            'is_error' => true,
            'result' => 'Tool execution cancelled by user.',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCancelled->value, $result->type);
    }

    public function testNormalizesToolExecutionEndStructuredCancellationMetadataToToolExecutionCancelled(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-rich-cancel',
            'order_index' => 0,
            'is_error' => true,
            'result' => 'Subagent scout cancelled by parent run.',
            'cancelled' => true,
            'cancellation_reason' => 'user',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCancelled->value, $result->type);
    }

    public function testNormalizesAgentEndCancelledToRunCancelled(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'cancelled']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunCancelled->value, $result->type);
        $this->assertSame('cancelled', $result->payload['reason']);
    }

    public function testNormalizesAgentEndFailedToRunFailed(): void
    {
        $event = $this->runEvent('agent_end', [
            'reason' => 'failed',
            'error' => 'CAS conflict exhausted',
            'message_type' => 'StartRun',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunFailed->value, $result->type);
        $this->assertSame('failed', $result->payload['reason']);
        $this->assertSame('CAS conflict exhausted', $result->payload['error']);
        $this->assertSame('StartRun', $result->payload['message_type']);
    }

    public function testNormalizesAgentEndUnknownReasonToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'some_unknown_reason']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        $this->assertSame('some_unknown_reason', $result->payload['reason']);
    }

    public function testNormalizesAgentEndMissingReasonToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', []);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        $this->assertSame('completed', $result->payload['reason']);
    }

    // ── Assistant stream normalization ───────────────────────────────────────

    public function testNormalizesLlmStepCompletedToAssistantMessageCompleted(): void
    {
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-1',
            'stop_reason' => 'stop',
            'usage' => ['total_tokens' => 100],
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, world!'],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::AssistantMessageCompleted->value, $result->type);
        $this->assertSame('turn-1-llm-1', $result->payload['message_id']);
        $this->assertSame('Hello, world!', $result->payload['text']);
        $this->assertSame('stop', $result->payload['stop_reason']);
        $this->assertSame(100, $result->payload['usage']['total_tokens']);
    }

    public function testNormalizesLlmStepCompletedWithMultipleTextBlocks(): void
    {
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-2',
            'stop_reason' => 'stop',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Part one. '],
                    ['type' => 'text', 'text' => 'Part two.'],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame('Part one. Part two.', $result->payload['text']);
    }

    public function testNormalizesLlmStepCompletedWithNoText(): void
    {
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-3',
            'stop_reason' => 'tool_use',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame('', $result->payload['text']);
        $this->assertSame('tool_use', $result->payload['stop_reason']);
    }

    public function testNormalizesLlmStepCompletedUsesExplicitTextKey(): void
    {
        // When LlmStepResultHandler emits 'text' via AssistantMessage::asText(),
        // the mapper should use that key preferentially over walking the
        // normalized assistant_message content array.
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-7',
            'stop_reason' => 'stop',
            'text' => 'Source-extracted via AssistantMessage::asText()',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Legacy-walked text that should be ignored'],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(
            'Source-extracted via AssistantMessage::asText()',
            $result->payload['text'],
            'Should use explicit text key, not walk the normalized payload',
        );
    }

    public function testNormalizesLlmStepCompletedTextKeyFallsBackToLegacy(): void
    {
        // When 'text' key is missing (older events), falls back to walking
        // the assistant_message content array.
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-8',
            'stop_reason' => 'stop',
            // No 'text' key — legacy path
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Legacy text here'],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame('Legacy text here', $result->payload['text']);
    }

    public function testNormalizesLlmStepCompletedMissingAssistantMessage(): void
    {
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-4',
            'stop_reason' => 'stop',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame('', $result->payload['text']);
    }

    public function testNormalizesLlmStepFailedToAssistantMessageFailed(): void
    {
        $event = $this->runEvent('llm_step_failed', [
            'step_id' => 'turn-1-llm-5',
            'error' => ['message' => 'APIConnectionError: timeout'],
            'retryable' => true,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::AssistantMessageFailed->value, $result->type);
        $this->assertSame('turn-1-llm-5', $result->payload['message_id']);
        $this->assertStringContainsString('APIConnectionError', $result->payload['text']);
        $this->assertSame('error', $result->payload['stop_reason']);
    }

    public function testNormalizesLlmStepFailedWithoutErrorMessage(): void
    {
        $event = $this->runEvent('llm_step_failed', [
            'step_id' => 'turn-1-llm-6',
            'error' => [],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::AssistantMessageFailed->value, $result->type);
        $this->assertSame('LLM step failed', $result->payload['text']);
    }

    public function testNormalizesLlmStepAbortedToTurnCancelled(): void
    {
        $event = $this->runEvent('llm_step_aborted', [
            'step_id' => 'turn-1-llm-7',
            'stop_reason' => 'timeout',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::TurnCancelled->value, $result->type);
        $this->assertSame('timeout', $result->payload['reason']);
    }

    // ── Tool normalization ───────────────────────────────────────────────────

    public function testNormalizesToolExecutionStart(): void
    {
        $event = $this->runEvent('tool_execution_start', [
            'tool_call_id' => 'call-read',
            'tool_name' => 'read_file',
            'order_index' => 0,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionStarted->value, $result->type);
        $this->assertSame('call-read', $result->payload['tool_call_id']);
        $this->assertSame('read_file', $result->payload['tool_name']);
        $this->assertSame(0, $result->payload['order_index']);
    }

    public function testNormalizesToolExecutionEndSuccess(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-read',
            'is_error' => false,
            'order_index' => 0,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $result->type);
        $this->assertFalse($result->payload['is_error']);
    }

    public function testNormalizesToolExecutionEndError(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-broken',
            'is_error' => true,
            'order_index' => 1,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionFailed->value, $result->type);
        $this->assertTrue($result->payload['is_error']);
    }

    public function testNormalizesToolExecutionEndPassesThroughResultText(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-read',
            'is_error' => false,
            'order_index' => 0,
            'result' => 'actual tool output content',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $result->type);
        $this->assertArrayHasKey('result', $result->payload);
        $this->assertSame('actual tool output content', $result->payload['result'],
            'Translator must pass through result text to the protocol event');
    }

    public function testNormalizesToolExecutionEndOmitsResultWhenNotString(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-read',
            'is_error' => false,
            'order_index' => 0,
            'result' => 42,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $result->type);
        // Translator only forwards string results; non-string should be omitted
        // so the projector falls back to "{tool_name} completed".
        $this->assertArrayNotHasKey('result', $result->payload);
    }

    // ── HITL normalization ───────────────────────────────────────────────────

    public function testNormalizesWaitingHumanToHumanInputRequested(): void
    {
        $event = $this->runEvent('waiting_human', [
            'question_id' => 'q-approve-1',
            'prompt' => 'Approve deployment?',
            'schema' => ['type' => 'boolean'],
            'tool_call_id' => 'call-ask',
            'tool_name' => 'ask_human',
            'kind' => 'interrupt',
            'ui_kind' => 'approval',
            'header' => 'Confirm Action',
            'choices' => [['label' => 'Yes'], ['label' => 'No']],
            'default' => null,
            'allow_other' => false,
            'secret' => false,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $result->type);
        // Core fields
        $this->assertSame('q-approve-1', $result->payload['question_id']);
        $this->assertSame('Approve deployment?', $result->payload['prompt']);
        $this->assertSame(['type' => 'boolean'], $result->payload['schema']);
        $this->assertSame('call-ask', $result->payload['tool_call_id']);
        $this->assertSame('ask_human', $result->payload['tool_name']);
        // Both transport marker (kind) and UI semantics (ui_kind) survive
        $this->assertSame('interrupt', $result->payload['kind']);
        $this->assertSame('approval', $result->payload['ui_kind']);
        // Rich UI fields survive via generic passthrough (no blanket array_filter)
        $this->assertSame('Confirm Action', $result->payload['header']);
        $this->assertSame([['label' => 'Yes'], ['label' => 'No']], $result->payload['choices']);
        // Explicit key-presence assertion makes the no-array_filter contract
        // version-independent (does not rely on PHPUnit's undefined-key handling).
        $this->assertArrayHasKey('default', $result->payload);
        $this->assertNull($result->payload['default']);
        $this->assertFalse($result->payload['allow_other']);
        $this->assertFalse($result->payload['secret']);
    }

    public function testNormalizesWaitingHumanMinimalPayload(): void
    {
        $event = $this->runEvent('waiting_human', [
            'question_id' => 'q-minimal-1',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $result->type);
        $this->assertSame('q-minimal-1', $result->payload['question_id']);
        $this->assertSame('Human input required.', $result->payload['prompt']);
        $this->assertSame(['type' => 'string'], $result->payload['schema']);
    }

    // ── HITL answers ─────────────────────────────────────────────────────────

    public function testNormalizesAgentCommandAppliedHumanResponse(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'human_response',
            'question_id' => 'q-approve-1',
            'answer' => 'yes',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputAnswered->value, $result->type);
        $this->assertSame('q-approve-1', $result->payload['question_id']);
        $this->assertSame('yes', $result->payload['answer']);
    }

    // ── Cancel normalization ─────────────────────────────────────────────────

    public function testNormalizesAgentCommandAppliedHumanResponseBooleanAnswer(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'human_response',
            'question_id' => 'q-confirm-bool',
            'answer' => false,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::HumanInputAnswered->value, $result->type);
        $this->assertFalse($result->payload['answer']);
    }

    public function testNormalizesAgentCommandAppliedCancel(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'cancel',
            'idempotency_key' => 'cancel-key-1',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::CancellationRequested->value, $result->type);
        $this->assertSame('cancel', $result->payload['kind']);
        $this->assertSame('user_cancelled', $result->payload['reason']);
    }

    public function testNormalizesAgentCommandAppliedSteerToUserMessageSubmitted(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'steer',
            'idempotency_key' => 'steer-key-1',
            'message' => [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, steer message'],
                ],
            ],
            'text' => 'Hello, steer message',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::UserMessageSubmitted->value, $result->type);
        $this->assertSame('Hello, steer message', $result->payload['text']);
        $this->assertStringContainsString('steer-key-1', $result->payload['message_id']);
    }

    public function testNormalizesAgentCommandAppliedFollowUpToUserMessageSubmitted(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'follow_up',
            'idempotency_key' => 'follow-key-1',
            'message' => [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'A follow-up question'],
                ],
            ],
            'text' => 'A follow-up question',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::UserMessageSubmitted->value, $result->type);
        $this->assertSame('A follow-up question', $result->payload['text']);
        $this->assertStringContainsString('follow-key-1', $result->payload['message_id']);
    }

    public function testNormalizesAgentCommandAppliedFollowUpExtractsTextFromMessage(): void
    {
        // When 'text' key is missing, should extract from serialized message content.
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'follow_up',
            'idempotency_key' => 'follow-key-2',
            'message' => [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Extracted from content'],
                ],
            ],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::UserMessageSubmitted->value, $result->type);
        $this->assertSame('Extracted from content', $result->payload['text']);
    }

    // ── Skipped internal events ──────────────────────────────────────────────

    public function testSkipsToolCallResultReceived(): void
    {
        $event = $this->runEvent('tool_call_result_received', ['tool_call_id' => 'call-x']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result);
    }

    public function testSkipsToolBatchCommitted(): void
    {
        $event = $this->runEvent('tool_batch_committed', ['count' => 3]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result);
    }

    public function testNormalizesAgentCommandQueuedSteerToUserMessageQueued(): void
    {
        $event = $this->runEvent('agent_command_queued', [
            'kind' => 'steer',
            'idempotency_key' => 'ik-steer-1',
            'message' => [
                'content' => [['type' => 'text', 'text' => 'Steer from content']],
            ],
        ], seq: 7);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::UserMessageQueued->value, $result->type);
        $this->assertSame('Steer from content', $result->payload['text']);
        $this->assertSame('ik-steer-1', $result->payload['idempotency_key']);
        $this->assertArrayNotHasKey('message_id', $result->payload);
    }

    public function testSkipsAgentCommandQueuedFollowUpToAvoidIdlePendingFlicker(): void
    {
        // Thesis: idle follow_up applies immediately and projects as ❯; mapping to
        // user.message_queued only causes a brief ⏳ flicker with no user value.
        $event = $this->runEvent('agent_command_queued', [
            'kind' => 'follow_up',
            'idempotency_key' => 'ik-fu-2',
            'text' => 'Direct text',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result);
    }

    public function testSkipsAgentCommandQueuedForCompact(): void
    {
        $event = $this->runEvent('agent_command_queued', ['kind' => 'compact']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result);
    }

    public function testSkipsAgentCommandSuperseded(): void
    {
        $event = $this->runEvent('agent_command_superseded', ['kind' => 'steer']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result);
    }

    public function testSkipsTurnBranched(): void
    {
        $event = $this->runEvent('turn_branched', [
            'turn_no' => 1,
            'parent_turn_no' => null,
            'reason' => 'rewind',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result, 'turn_branched is tree metadata and must not produce a runtime event');
    }

    public function testSkipsLeafSet(): void
    {
        $event = $this->runEvent('leaf_set', [
            'turn_no' => 2,
            'parent_turn_no' => 1,
            'previous_turn_no' => 1,
            'reason' => 'continue',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNull($result, 'leaf_set is tree metadata and must not produce a runtime event');
    }

    // ── Status fallback normalization ────────────────────────────────────────

    public function testNormalizesAgentCommandRejectedToStatusUpdated(): void
    {
        $event = $this->runEvent('agent_command_rejected', [
            'kind' => 'continue',
            'reason' => 'Run is cancelled.',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        $this->assertSame('agent_command_rejected', $result->payload['debug.raw_type']);
    }

    public function testNormalizesStaleResultIgnoredToStatusUpdated(): void
    {
        $event = $this->runEvent('stale_result_ignored', [
            'result' => 'tool_call_result',
            'tool_call_id' => 'call-stale',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        $this->assertSame('stale_result_ignored', $result->payload['debug.raw_type']);
    }

    // ── Compaction ───────────────────────────────────────────────────────────

    public function testNormalizesCompactionFailedEmptySummary(): void
    {
        $event = $this->runEvent('context_compaction_failed', [
            'reason' => 'empty_summary',
            'message' => 'Compaction failed: summarization model returned an empty summary.',
            'messages_replaced' => false,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::CompactionFailed->value, $result->type);
        $this->assertSame('empty_summary', $result->payload['reason']);
        $this->assertSame(
            'Compaction failed: The model returned an empty summary.',
            $result->payload['error'],
        );
    }

    /**
     * The translator reads the message from the compaction-failed payload.
     * When CompactionStepResultHandler sets message to the classifier's
     * user_message, the translator preserves it.  The handler now prefers
     * user_message from LlmProviderErrorClassifier, so the raw provider
     * exception text is never surfaced.
     */
    public function testNormalizesCompactionFailedModelErrorWithClassifierMessage(): void
    {
        // Simulates what CompactionStepResultHandler now stores in 'message':
        // the sanitised user_message from LlmProviderErrorClassifier.
        $event = $this->runEvent('context_compaction_failed', [
            'reason' => 'model_error',
            'message' => 'LLM provider rejected the request (HTTP 400): Request body is malformed...',
            'messages_replaced' => false,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::CompactionFailed->value, $result->type);
        $this->assertSame('model_error', $result->payload['reason']);
        $this->assertStringStartsWith(
            'Compaction failed:',
            $result->payload['error'],
        );
        $this->assertStringContainsString(
            'LLM provider rejected',
            $result->payload['error'],
        );
    }

    public function testNormalizesCompactionFailedModelErrorWithoutMessage(): void
    {
        $event = $this->runEvent('context_compaction_failed', [
            'reason' => 'model_error',
            'messages_replaced' => false,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::CompactionFailed->value, $result->type);
        $this->assertSame('model_error', $result->payload['reason']);
        $this->assertStringContainsString(
            'unexpected error',
            $result->payload['error'],
        );
    }

    // ── Unknown event normalization ──────────────────────────────────────────

    public function testNormalizesUnknownEventToStatusUpdatedWithDebug(): void
    {
        $event = $this->runEvent('some_future_event', ['future_key' => 'value']);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        $this->assertSame('some_future_event', $result->payload['debug.raw_type']);
        $this->assertSame('value', $result->payload['debug.raw_payload']['future_key']);
    }

    // ── toRunEventData backward compat ───────────────────────────────────────

    public function testToRunEventDataPreservesStableShape(): void
    {
        $raw = $this->runEvent('llm_step_completed', [
            'step_id' => 's1',
            'assistant_message' => [
                'content' => [['type' => 'text', 'text' => 'Hi']],
            ],
        ]);
        $runtime = $this->mapper->toRuntimeEvent($raw);

        $this->assertNotNull($runtime);
        $data = $this->mapper->toRunEventData($runtime);

        $this->assertArrayHasKey('runId', $data);
        $this->assertArrayHasKey('seq', $data);
        $this->assertArrayHasKey('turnNo', $data);
        $this->assertArrayHasKey('type', $data);
        $this->assertArrayHasKey('payload', $data);
        $this->assertSame(RuntimeEventTypeEnum::AssistantMessageCompleted->value, $data['type']);
    }

    // ── Field mapping fidelity ───────────────────────────────────────────────

    public function testRunIdAndSeqArePreserved(): void
    {
        $event = $this->runEvent('run_started', ['step_id' => 's1'], seq: 42);

        $result = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($result);
        $this->assertSame($this->runId, $result->runId);
        $this->assertSame(42, $result->seq);
    }

    public function testAgentCommandAppliedAppendMessageMapsToUserMessageSubmitted(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'append_message',
            'idempotency_key' => 'append-key-1',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'runtime line']],
            ],
            'text' => 'runtime line',
        ], 12);

        $runtimeEvent = $this->mapper->toRuntimeEvent($event);

        $this->assertNotNull($runtimeEvent);
        $this->assertSame(RuntimeEventTypeEnum::UserMessageSubmitted->value, $runtimeEvent->type);
        $this->assertSame('runtime line', $runtimeEvent->payload['text'] ?? '');
    }

    // ── Test helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function runEvent(string $type, array $payload = [], int $seq = 1): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: 1,
            type: $type,
            payload: $payload,
        );
    }
}
