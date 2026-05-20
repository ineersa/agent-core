<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\CodingAgent\Runtime\Mapping\AssistantMessageMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Mapping\CancelAndFallbackMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Mapping\HitlMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Mapping\RunLifecycleMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Mapping\ToolExecutionMappingSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(RuntimeEventMapper::class)]
final class RuntimeEventMapperTest extends TestCase
{
    private RuntimeEventMapper $mapper;
    private string $runId = 'run-test-1';

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RunLifecycleMappingSubscriber());
        $dispatcher->addSubscriber(new AssistantMessageMappingSubscriber());
        $dispatcher->addSubscriber(new ToolExecutionMappingSubscriber());
        $dispatcher->addSubscriber(new HitlMappingSubscriber());
        $dispatcher->addSubscriber(new CancelAndFallbackMappingSubscriber());

        $this->mapper = new RuntimeEventMapper($dispatcher);
    }

    // ── Lifecycle normalization ──────────────────────────────────────────────

    public function testNormalizesRunStartedToRunStarted(): void
    {
        $event = $this->runEvent('run_started', ['step_id' => 'start-1']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunStarted->value, $result->type);
        self::assertSame('start-1', $result->payload['step_id']);
        self::assertSame($this->runId, $result->runId);
        self::assertSame(1, $result->seq);
    }

    public function testNormalizesTurnAdvancedToTurnStarted(): void
    {
        $event = $this->runEvent('turn_advanced', ['turn_no' => 3]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::TurnStarted->value, $result->type);
        self::assertSame(3, $result->payload['turn_no']);
    }

    public function testNormalizesAgentEndCompletedToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'completed']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        self::assertSame('completed', $result->payload['reason']);
    }

    public function testNormalizesAgentEndCancelledToRunCancelled(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'cancelled']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunCancelled->value, $result->type);
        self::assertSame('cancelled', $result->payload['reason']);
    }

    public function testNormalizesAgentEndFailedToRunFailed(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'failed']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunFailed->value, $result->type);
    }

    public function testNormalizesAgentEndUnknownReasonToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', ['reason' => 'some_unknown_reason']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        self::assertSame('some_unknown_reason', $result->payload['reason']);
    }

    public function testNormalizesAgentEndMissingReasonToRunCompleted(): void
    {
        $event = $this->runEvent('agent_end', []);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::RunCompleted->value, $result->type);
        self::assertSame('completed', $result->payload['reason']);
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

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::AssistantMessageCompleted->value, $result->type);
        self::assertSame('turn-1-llm-1', $result->payload['message_id']);
        self::assertSame('Hello, world!', $result->payload['text']);
        self::assertSame('stop', $result->payload['stop_reason']);
        self::assertSame(100, $result->payload['usage']['total_tokens']);
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

        self::assertNotNull($result);
        self::assertSame('Part one. Part two.', $result->payload['text']);
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

        self::assertNotNull($result);
        self::assertSame('', $result->payload['text']);
        self::assertSame('tool_use', $result->payload['stop_reason']);
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

        self::assertNotNull($result);
        self::assertSame(
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

        self::assertNotNull($result);
        self::assertSame('Legacy text here', $result->payload['text']);
    }

    public function testNormalizesLlmStepCompletedMissingAssistantMessage(): void
    {
        $event = $this->runEvent('llm_step_completed', [
            'step_id' => 'turn-1-llm-4',
            'stop_reason' => 'stop',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame('', $result->payload['text']);
    }

    public function testNormalizesLlmStepFailedToAssistantMessageFailed(): void
    {
        $event = $this->runEvent('llm_step_failed', [
            'step_id' => 'turn-1-llm-5',
            'error' => ['message' => 'APIConnectionError: timeout'],
            'retryable' => true,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::AssistantMessageFailed->value, $result->type);
        self::assertSame('turn-1-llm-5', $result->payload['message_id']);
        self::assertStringContainsString('APIConnectionError', $result->payload['text']);
        self::assertSame('error', $result->payload['stop_reason']);
    }

    public function testNormalizesLlmStepFailedWithoutErrorMessage(): void
    {
        $event = $this->runEvent('llm_step_failed', [
            'step_id' => 'turn-1-llm-6',
            'error' => [],
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::AssistantMessageFailed->value, $result->type);
        self::assertSame('LLM step failed', $result->payload['text']);
    }

    public function testNormalizesLlmStepAbortedToTurnCancelled(): void
    {
        $event = $this->runEvent('llm_step_aborted', [
            'step_id' => 'turn-1-llm-7',
            'stop_reason' => 'timeout',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::TurnCancelled->value, $result->type);
        self::assertSame('timeout', $result->payload['reason']);
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

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::ToolExecutionStarted->value, $result->type);
        self::assertSame('call-read', $result->payload['tool_call_id']);
        self::assertSame('read_file', $result->payload['tool_name']);
        self::assertSame(0, $result->payload['order_index']);
    }

    public function testNormalizesToolExecutionEndSuccess(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-read',
            'is_error' => false,
            'order_index' => 0,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $result->type);
        self::assertFalse($result->payload['is_error']);
    }

    public function testNormalizesToolExecutionEndError(): void
    {
        $event = $this->runEvent('tool_execution_end', [
            'tool_call_id' => 'call-broken',
            'is_error' => true,
            'order_index' => 1,
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::ToolExecutionFailed->value, $result->type);
        self::assertTrue($result->payload['is_error']);
    }

    // ── HITL normalization ───────────────────────────────────────────────────

    public function testNormalizesWaitingHumanToHumanInputRequested(): void
    {
        $event = $this->runEvent('waiting_human', [
            'question_id' => 'q-approve-1',
            'prompt' => 'Approve deployment?',
            'schema' => ['type' => 'boolean'],
            'tool_call_id' => 'call-ask',
            'tool_name' => 'ask_user',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $result->type);
        self::assertSame('q-approve-1', $result->payload['question_id']);
        self::assertSame('Approve deployment?', $result->payload['prompt']);
        self::assertSame(['type' => 'boolean'], $result->payload['schema']);
        self::assertSame('call-ask', $result->payload['tool_call_id']);
        self::assertSame('ask_user', $result->payload['tool_name']);
    }

    public function testNormalizesWaitingHumanMinimalPayload(): void
    {
        $event = $this->runEvent('waiting_human', [
            'question_id' => 'q-minimal-1',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::HumanInputRequested->value, $result->type);
        self::assertSame('q-minimal-1', $result->payload['question_id']);
        self::assertSame('Human input required.', $result->payload['prompt']);
    }

    // ── Cancel normalization ─────────────────────────────────────────────────

    public function testNormalizesAgentCommandAppliedCancel(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'cancel',
            'idempotency_key' => 'cancel-key-1',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::CancellationRequested->value, $result->type);
        self::assertSame('cancel', $result->payload['kind']);
        self::assertSame('user_cancelled', $result->payload['reason']);
    }

    public function testNormalizesAgentCommandAppliedNonCancel(): void
    {
        $event = $this->runEvent('agent_command_applied', [
            'kind' => 'steer',
            'idempotency_key' => 'steer-key-1',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        self::assertSame('agent_command_applied', $result->payload['debug.raw_type']);
    }

    // ── Skipped internal events ──────────────────────────────────────────────

    public function testSkipsToolCallResultReceived(): void
    {
        $event = $this->runEvent('tool_call_result_received', ['tool_call_id' => 'call-x']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNull($result);
    }

    public function testSkipsToolBatchCommitted(): void
    {
        $event = $this->runEvent('tool_batch_committed', ['count' => 3]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNull($result);
    }

    public function testSkipsAgentCommandQueued(): void
    {
        $event = $this->runEvent('agent_command_queued', ['kind' => 'steer']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNull($result);
    }

    public function testSkipsAgentCommandSuperseded(): void
    {
        $event = $this->runEvent('agent_command_superseded', ['kind' => 'steer']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNull($result);
    }

    // ── Status fallback normalization ────────────────────────────────────────

    public function testNormalizesAgentCommandRejectedToStatusUpdated(): void
    {
        $event = $this->runEvent('agent_command_rejected', [
            'kind' => 'continue',
            'reason' => 'Run is cancelled.',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        self::assertSame('agent_command_rejected', $result->payload['debug.raw_type']);
    }

    public function testNormalizesStaleResultIgnoredToStatusUpdated(): void
    {
        $event = $this->runEvent('stale_result_ignored', [
            'result' => 'tool_call_result',
            'tool_call_id' => 'call-stale',
        ]);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        self::assertSame('stale_result_ignored', $result->payload['debug.raw_type']);
    }

    // ── Unknown event normalization ──────────────────────────────────────────

    public function testNormalizesUnknownEventToStatusUpdatedWithDebug(): void
    {
        $event = $this->runEvent('some_future_event', ['future_key' => 'value']);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame(RuntimeEventTypeEnum::StatusUpdated->value, $result->type);
        self::assertSame('some_future_event', $result->payload['debug.raw_type']);
        self::assertSame('value', $result->payload['debug.raw_payload']['future_key']);
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

        self::assertNotNull($runtime);
        $data = $this->mapper->toRunEventData($runtime);

        self::assertArrayHasKey('runId', $data);
        self::assertArrayHasKey('seq', $data);
        self::assertArrayHasKey('turnNo', $data);
        self::assertArrayHasKey('type', $data);
        self::assertArrayHasKey('payload', $data);
        self::assertSame(RuntimeEventTypeEnum::AssistantMessageCompleted->value, $data['type']);
    }

    // ── Field mapping fidelity ───────────────────────────────────────────────

    public function testRunIdAndSeqArePreserved(): void
    {
        $event = $this->runEvent('run_started', ['step_id' => 's1'], seq: 42);

        $result = $this->mapper->toRuntimeEvent($event);

        self::assertNotNull($result);
        self::assertSame($this->runId, $result->runId);
        self::assertSame(42, $result->seq);
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
