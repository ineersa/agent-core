<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayService;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RunStateReplayServiceTest extends TestCase
{
    private RunEventStore $eventStore;
    private RunStateReplayService $service;
    private string $runId = 'run-replay-test';

    protected function setUp(): void
    {
        $this->eventStore = new RunEventStore();
        $this->service = new RunStateReplayService(
            $this->eventStore,
            new NullLogger(),
        );
    }

    public function testNoEventsReturnsNoEventsResult(): void
    {
        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertFalse($result->hadEvents);
        self::assertFalse($result->rebuilt);
        self::assertFalse($result->wasStale);
        self::assertNull($result->rebuiltState);
    }

    public function testCurrentStateNotRebuilt(): void
    {
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $state = new RunState(
            runId: $this->runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 1, // current — matches max event seq
        );

        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertFalse($result->rebuilt);
        self::assertTrue($result->hadEvents);
        self::assertNull($result->rebuiltState);
    }

    public function testStaleStateIsRebuilt(): void
    {
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $state = new RunState(
            runId: $this->runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0, // stale — behind max event seq
        );

        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertNotNull($result->rebuiltState);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status);
        self::assertSame(1, $result->rebuiltState->lastSeq);
    }

    public function testMissingStateWithEventsIsRebuilt(): void
    {
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $state = RunState::queued($this->runId); // lastSeq = 0, no stored state

        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertNotNull($result->rebuiltState);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status);
    }

    // ── Initial prompt + assistant response ─────────────────────────────────

    public function testReplayInitialPromptAndAssistantResponse(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 'step-init',
            'payload' => [
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'You are helpful.']], 'is_error' => false],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']], 'is_error' => false],
                ],
            ],
        ]);

        $this->appendEvent(RunEventTypeEnum::TurnAdvanced->value, 2, [
            'step_id' => 'step-adv-1',
            'turn_no' => 1,
        ]);

        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, [
            'step_id' => 'step-adv-1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi! How can I help?']],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertNotNull($result->rebuiltState);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status);
        self::assertSame(1, $result->rebuiltState->turnNo);
        self::assertSame('step-adv-1', $result->rebuiltState->activeStepId);
        self::assertSame(3, $result->rebuiltState->lastSeq);

        $messages = $result->rebuiltState->messages;
        self::assertCount(3, $messages);
        self::assertSame('system', $messages[0]->role);
        self::assertSame('user', $messages[1]->role);
        self::assertSame('assistant', $messages[2]->role);
        self::assertSame('Hi! How can I help?', $messages[2]->content[0]['text']);
    }

    // ── Follow-up / steer replay ────────────────────────────────────────────

    public function testReplaySteerOrFollowUpAppendsUserMessage(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 2, [
            'kind' => 'follow_up',
            'idempotency_key' => 'idem-1',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Actually, write it in Python.']],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $messages = $result->rebuiltState->messages;
        self::assertCount(1, $messages);
        self::assertSame('user', $messages[0]->role);
        self::assertStringContainsString('Python', $messages[0]->content[0]['text']);
    }

    // ── Tool result replay ──────────────────────────────────────────────────

    public function testReplayToolCallPath(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        // Assistant message with tool call
        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, [
            'step_id' => 's1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Let me check.']],
                'tool_calls' => [
                    ['id' => 'tc-1', 'name' => 'read', 'arguments' => [], 'order_index' => 0],
                ],
                'is_error' => false,
            ],
        ]);

        // Tool execution start
        $this->appendEvent(RunEventTypeEnum::ToolExecutionStart->value, 3, [
            'tool_call_id' => 'tc-1',
            'tool_name' => 'read',
        ]);

        // Tool result received
        $this->appendEvent(RunEventTypeEnum::ToolCallResultReceived->value, 4, [
            'tool_call_id' => 'tc-1',
            'order_index' => 0,
            'is_error' => false,
        ]);

        // Tool execution end
        $this->appendEvent(RunEventTypeEnum::ToolExecutionEnd->value, 5, [
            'tool_call_id' => 'tc-1',
            'order_index' => 0,
            'is_error' => false,
        ]);

        // Message start for tool
        $this->appendEvent(RunEventTypeEnum::MessageStart->value, 6, [
            'message_role' => 'tool',
            'tool_call_id' => 'tc-1',
        ]);

        // Message end for tool with serialized result
        $this->appendEvent(RunEventTypeEnum::MessageEnd->value, 7, [
            'message_role' => 'tool',
            'tool_call_id' => 'tc-1',
            'message' => [
                'role' => 'tool',
                'content' => [['type' => 'text', 'text' => '{"is_error":false,"result":"file content"}']],
                'tool_call_id' => 'tc-1',
                'tool_name' => 'read',
                'is_error' => false,
            ],
        ]);

        // Batch committed
        $this->appendEvent(RunEventTypeEnum::ToolBatchCommitted->value, 8, [
            'count' => 1,
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $rebuiltState = $result->rebuiltState;
        self::assertNotNull($rebuiltState);
        $messages = $rebuiltState->messages;
        self::assertCount(2, $messages); // assistant + tool
        self::assertSame('assistant', $messages[0]->role);
        self::assertSame('tool', $messages[1]->role);
        self::assertSame([], $rebuiltState->pendingToolCalls);
    }

    // ── HITL replay ─────────────────────────────────────────────────────────

    public function testReplayHitlWaitingAndResponse(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        // Waiting human
        $this->appendEvent(RunEventTypeEnum::WaitingHuman->value, 2, [
            'tool_call_id' => 'tc-h',
            'tool_name' => 'ask_user',
            'question_id' => 'q1',
            'prompt' => 'Proceed?',
        ]);

        // Human response
        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 3, [
            'kind' => 'human_response',
            'idempotency_key' => 'idem-hr',
            'question_id' => 'q1',
            'answer' => 'yes',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => '{"question_id":"q1","answer":"yes"}']],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $rebuiltState = $result->rebuiltState;
        self::assertSame(RunStatus::Running, $rebuiltState->status);
        self::assertCount(1, $rebuiltState->messages);
        self::assertSame('user', $rebuiltState->messages[0]->role);
    }

    // ── Cancellation replay ─────────────────────────────────────────────────

    public function testReplayCancellation(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 2, [
            'kind' => 'cancel',
            'idempotency_key' => 'idem-c',
            'reason' => 'User cancelled.',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $rebuiltState = $result->rebuiltState;
        self::assertSame(RunStatus::Cancelling, $rebuiltState->status);
        self::assertSame('User cancelled.', $rebuiltState->errorMessage);
    }

    public function testReplayAgentEndCancelled(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentEnd->value, 2, [
            'reason' => 'cancelled',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Cancelled, $result->rebuiltState->status);
    }

    // ── Error / llm_step_failed replay ──────────────────────────────────────

    public function testReplayLlmStepFailed(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::LlmStepFailed->value, 2, [
            'error' => ['message' => 'API timeout', 'code' => 504],
            'retryable' => true,
            'step_id' => 's1',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $rebuiltState = $result->rebuiltState;
        self::assertSame(RunStatus::Failed, $rebuiltState->status);
        self::assertSame('API timeout', $rebuiltState->errorMessage);
        self::assertTrue($rebuiltState->retryableFailure);
    }

    // ── Idempotent replay ───────────────────────────────────────────────────

    public function testReplayIsIdempotentSameEventsProduceEquivalentState(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']], 'is_error' => false],
                ],
            ],
        ]);

        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi!']],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result1 = $this->service->rebuildIfStale($state, $this->runId);
        $result2 = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result1->rebuilt);
        self::assertTrue($result2->rebuilt);
        self::assertNotNull($result1->rebuiltState);
        self::assertNotNull($result2->rebuiltState);

        $s1 = $result1->rebuiltState;
        $s2 = $result2->rebuiltState;

        self::assertSame($s1->status, $s2->status);
        self::assertSame($s1->lastSeq, $s2->lastSeq);
        self::assertCount(\count($s1->messages), $s2->messages);
        self::assertSame($s1->messages[0]->role, $s2->messages[0]->role);
        self::assertSame($s1->messages[1]->role, $s2->messages[1]->role);
    }

    // ── Non-contiguous history ──────────────────────────────────────────────

    public function testNonContiguousHistoryThrowsException(): void
    {
        // Missing seq 2
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $this->appendEvent('run_started', 3, ['step_id' => 's3', 'payload' => ['messages' => []]]);

        $state = new RunState(
            runId: $this->runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0, // stale
        );

        $this->expectException(RunStateReplayException::class);
        $this->expectExceptionMessage('missing sequences');

        $this->service->rebuildIfStale($state, $this->runId);
    }

    // ── Replay preserves stored version ────────────────────────────────────

    public function testRebuiltStatePreservesStoredVersion(): void
    {
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $state = new RunState(
            runId: $this->runId,
            status: RunStatus::Running,
            version: 5,
            turnNo: 0,
            lastSeq: 0,
        );

        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(5, $result->rebuiltState->version);
    }

    public function testRebuiltFromQueuedHasVersionZero(): void
    {
        $this->appendEvent('run_started', 1, ['step_id' => 's1', 'payload' => ['messages' => []]]);
        $state = RunState::queued($this->runId);

        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(0, $result->rebuiltState->version);
    }

    // ── Multiple messages from run_started ──────────────────────────────────

    public function testRunStartedWithMultipleMessagesRebuildsAll(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => [
                'messages' => [
                    ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'System prompt.']], 'is_error' => false],
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'User prompt.']], 'is_error' => false],
                ],
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $messages = $result->rebuiltState->messages;
        self::assertCount(2, $messages);
        self::assertSame('system', $messages[0]->role);
        self::assertSame('user', $messages[1]->role);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(string $type, int $seq, array $payload): void
    {
        $this->eventStore->append(new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: 0,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        ));
    }
}
