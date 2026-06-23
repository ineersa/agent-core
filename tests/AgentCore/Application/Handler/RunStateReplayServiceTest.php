<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayService;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
use Ineersa\AgentCore\Application\Replay\TurnTreeReplayFilter;
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
    private TurnTreeReplayFilter $treeFilter;
    private string $runId = 'run-replay-test';

    protected function setUp(): void
    {
        $this->eventStore = new RunEventStore();
        $this->treeFilter = new TurnTreeReplayFilter(new TurnTreeProjector());
        $this->service = new RunStateReplayService(
            $this->eventStore,
            new NullLogger(),
            $this->treeFilter,
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

    // ── Shell-only tool execution replay ──────────────────────────────────

    /**
     * Thesis: A shell tool_execution_start (registers tool_call_id => false)
     * followed by tool_execution_end (resolves to true), with NO LLM step
     * events, produces a RunState with zero unresolved tool calls.
     *
     * Before the applyToolExecutionEnd handler was added (issue #183), the
     * shell's pending tool call would stay unresolved, causing
     * AdvanceRunHandler to bail with an empty HandlerResult on follow-up
     * commands — making the run appear dead.
     */
    public function testToolExecutionEndResolvesPendingShellToolCallWithoutLlmStep(): void
    {
        // Shell command metadata (tool_call_id prefix 'sh_' is the convention
        // used by ShellCommandHandler).
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 'sh-step',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::ToolExecutionStart->value, 2, [
            'tool_call_id' => 'sh_shell_1',
            'tool_name' => 'bash',
        ]);

        // tool_execution_end resolves the pending call — the fix.
        $this->appendEvent(RunEventTypeEnum::ToolExecutionEnd->value, 3, [
            'tool_call_id' => 'sh_shell_1',
            'is_error' => false,
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt, 'Expected state rebuild when events exist.');
        $rebuilt = $result->rebuiltState;
        self::assertNotNull($rebuilt);

        // Critical: all entries in pendingToolCalls MUST be true (fully resolved)
        // via applyToolExecutionEnd — even without an LLM step that would
        // normally clear the map.  The AdvanceRunHandler guard iterates entries
        // and bails on any false (unresolved) call; all-true means no bail.
        self::assertNotEmpty(
            $rebuilt->pendingToolCalls,
            'Expected pendingToolCalls to contain the shell tool call.',
        );
        self::assertNotContains(
            false,
            $rebuilt->pendingToolCalls,
            'Shell-only tool calls must be fully resolved (all true) so AdvanceRun does not bail.',
        );
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

    // ── Pending tool call reset across steps ────────────────────────────────

    public function testLlmStepCompletedResetsPendingToolCallsFromPriorStep(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        // First LLM step with tool calls tc-1 and tc-2.
        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, [
            'step_id' => 's1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'First step.']],
                'tool_calls' => [
                    ['id' => 'tc-1', 'name' => 'read', 'arguments' => [], 'order_index' => 0],
                    ['id' => 'tc-2', 'name' => 'write', 'arguments' => [], 'order_index' => 1],
                ],
                'is_error' => false,
            ],
        ]);

        // Second LLM step with only tc-3.
        // If replay accumulates instead of resetting, pendingToolCalls will still
        // hold tc-1 and tc-2 alongside tc-3.
        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, [
            'step_id' => 's2',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Second step.']],
                'tool_calls' => [
                    ['id' => 'tc-3', 'name' => 'search', 'arguments' => [], 'order_index' => 0],
                ],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $pendingCalls = $result->rebuiltState->pendingToolCalls;
        self::assertCount(1, $pendingCalls, 'Only tc-3 should survive; tc-1 and tc-2 must be dropped.');
        self::assertArrayHasKey('tc-3', $pendingCalls);
        self::assertArrayNotHasKey('tc-1', $pendingCalls);
        self::assertArrayNotHasKey('tc-2', $pendingCalls);
        self::assertFalse($pendingCalls['tc-3']);
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

    // ── Tool-call-only assistant message replay ────────────────────────────

    public function testReplayToolCallOnlyAssistantMessage(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        // Tool-call-only assistant message: content is null, tool_calls at root.
        // This matches AgentMessageNormalizer::assistantMessagePayload() shape.
        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 2, [
            'step_id' => 's1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    ['id' => 'tc-1', 'name' => 'read', 'arguments' => [], 'order_index' => 0],
                ],
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $messages = $result->rebuiltState->messages;
        self::assertCount(1, $messages, 'Tool-call-only assistant message must be replayed.');
        self::assertSame('assistant', $messages[0]->role);
        self::assertSame([], $messages[0]->content);
        self::assertArrayHasKey('tool_calls', $messages[0]->metadata);
        self::assertCount(1, $messages[0]->metadata['tool_calls']);
        self::assertSame('tc-1', $messages[0]->metadata['tool_calls'][0]['id']);

        // Pending tool calls must be populated.
        $pendingCalls = $result->rebuiltState->pendingToolCalls;
        self::assertArrayHasKey('tc-1', $pendingCalls);
        self::assertFalse($pendingCalls['tc-1']);
    }

    // ── Duplicate sequence detection ──────────────────────────────────────

    public function testDuplicateSequenceThrowsException(): void
    {
        // Two events with the same seq number.
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's2',
            'payload' => ['messages' => []],
        ]);

        $state = new RunState(
            runId: $this->runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 0,
            lastSeq: 0,
        );

        $this->expectException(RunStateReplayException::class);
        $this->expectExceptionMessage('duplicate sequence');

        $this->service->rebuildIfStale($state, $this->runId);
    }

    // ── Command rejected replay ─────────────────────────────────────────────

    public function testReplayAgentCommandRejected(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandRejected->value, 2, [
            'kind' => 'cancel',
            'reason' => 'Run already cancelling.',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Rejected command must not change status.');
        self::assertSame('Run already cancelling.', $result->rebuiltState->errorMessage);
    }

    // ── Agent command applied kind 'continue' ───────────────────────────────

    public function testReplayAgentCommandAppliedContinue(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => ['messages' => []],
        ]);

        // WaitingHuman event followed by continue command.
        $this->appendEvent(RunEventTypeEnum::WaitingHuman->value, 2, [
            'tool_call_id' => 'tc-h',
            'tool_name' => 'ask_user',
            'question_id' => 'q1',
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 3, [
            'kind' => 'continue',
            'idempotency_key' => 'idem-cont',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Continue command must restore Running status.');
        self::assertNull($result->rebuiltState->errorMessage);
    }

    // ── LlmStepAborted no mutation ──────────────────────────────────────────

    public function testReplayLlmStepAbortedNoMutation(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 's1',
            'payload' => [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']], 'is_error' => false],
                ],
            ],
        ]);

        // llm_step_aborted carries no assistant message content.
        $this->appendEvent(RunEventTypeEnum::LlmStepAborted->value, 2, [
            'step_id' => 's1',
            'stop_reason' => 'cancelled',
            'usage' => ['total_tokens' => 0],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        // Only the initial user message should be present.
        self::assertCount(1, $result->rebuiltState->messages, 'llm_step_aborted must not append a message.');
        self::assertSame('user', $result->rebuiltState->messages[0]->role);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status);
    }

    // ── Branch replay ───────────────────────────────────────────────────────

    public function testBranchReplayExcludesAbandonedTurnMessages(): void
    {
        // Build a canonical stream where:
        //   - Turn 1: initial user message, assistant response
        //   - Turn 2: follow-up user message, assistant response (abandoned)
        //   - Turn 3: new user message, assistant response (active branch from turn 1)
        $this->appendEventWithTurn('run_started', 1, 0, [
            'step_id' => 's0',
            'payload' => ['messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']], 'is_error' => false],
            ]],
        ]);

        // Turn 1
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 2, 1, [
            'turn_no' => 1,
            'parent_turn_no' => null,
            'step_id' => 'step-1',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 3, 1, [
            'turn_no' => 1,
            'parent_turn_no' => null,
            'previous_turn_no' => null,
            'reason' => 'continue',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LlmStepCompleted->value, 4, 1, [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hi! How can I help?']],
                'is_error' => false,
            ],
        ]);

        // Turn 2: follow-up from user (ABANDONED branch)
        $this->appendEventWithTurn(RunEventTypeEnum::AgentCommandApplied->value, 5, 1, [
            'kind' => 'steer',
            'idempotency_key' => 'steer-2',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Write it in Python.']],
                'is_error' => false,
            ],
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 6, 2, [
            'turn_no' => 2,
            'parent_turn_no' => 1,
            'step_id' => 'step-2',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 7, 2, [
            'turn_no' => 2,
            'parent_turn_no' => 1,
            'previous_turn_no' => 1,
            'reason' => 'continue',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LlmStepCompleted->value, 8, 2, [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ABANDONED Python code here...']],
                'is_error' => false,
            ],
        ]);

        // Turn 3: new user message branching from turn 1 (ACTIVE branch)
        $this->appendEventWithTurn(RunEventTypeEnum::AgentCommandApplied->value, 9, 1, [
            'kind' => 'steer',
            'idempotency_key' => 'steer-3',
            'message' => [
                'role' => 'user',
                'content' => [['type' => 'text', 'text' => 'Actually, write it in Rust.']],
                'is_error' => false,
            ],
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 10, 1, [
            'turn_no' => 1,
            'parent_turn_no' => null,
            'previous_turn_no' => 2,
            'reason' => 'rewind',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 11, 3, [
            'turn_no' => 3,
            'parent_turn_no' => 1,
            'step_id' => 'step-3',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 12, 3, [
            'turn_no' => 3,
            'parent_turn_no' => 1,
            'previous_turn_no' => 1,
            'reason' => 'continue',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LlmStepCompleted->value, 13, 3, [
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ACTIVE Rust code here...']],
                'is_error' => false,
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertNotNull($result->rebuiltState);

        $messages = $result->rebuiltState->messages;

        // Should include: system, initial user, turn 1 assistant, steer-2 user,
        // steer-3 user, turn 3 assistant.
        // Must NOT include: turn 2 assistant (abandoned branch).
        $assistantTexts = [];
        foreach ($messages as $msg) {
            if ('assistant' === $msg->role && [] !== $msg->content) {
                $assistantTexts[] = $msg->content[0]['text'] ?? '';
            }
        }

        self::assertContains('Hi! How can I help?', $assistantTexts, 'Turn 1 assistant must be present');
        self::assertContains('ACTIVE Rust code here...', $assistantTexts, 'Turn 3 assistant must be present');
        self::assertNotContains('ABANDONED Python code here...', $assistantTexts, 'Turn 2 assistant must be excluded');

        // lastSeq must be the full canonical max (13), not the last filtered event.
        self::assertSame(13, $result->rebuiltState->lastSeq);
        self::assertSame(3, $result->rebuiltState->turnNo, 'Turn number should be 3 (current leaf)');
    }

    public function testBranchReplayThrowsNoExceptionDespiteFilteredGaps(): void
    {
        // Verify that branch filtering does NOT trigger a non-contiguous
        // exception. Integrity checks run on the full stream which is contiguous.
        $this->appendEventWithTurn('run_started', 1, 0, [
            'step_id' => 's0',
            'payload' => ['messages' => []],
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 2, 1, [
            'turn_no' => 1, 'parent_turn_no' => null, 'step_id' => 's1',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 3, 1, [
            'turn_no' => 1, 'reason' => 'continue',
        ]);
        // Turn 2: abandoned
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 4, 2, [
            'turn_no' => 2, 'parent_turn_no' => 1, 'step_id' => 's2',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 5, 2, [
            'turn_no' => 2, 'reason' => 'continue',
        ]);
        // Rewind to turn 1 and create turn 3
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 6, 1, [
            'turn_no' => 1, 'reason' => 'rewind',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::TurnAdvanced->value, 7, 3, [
            'turn_no' => 3, 'parent_turn_no' => 1, 'step_id' => 's3',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 8, 3, [
            'turn_no' => 3, 'reason' => 'continue',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(8, $result->rebuiltState->lastSeq, 'lastSeq must be the full canonical max');
        self::assertSame(3, $result->rebuiltState->turnNo);
    }

    // ── Leaf_set and turn_branched are no-op reducers ───────────────────────

    public function testLeafSetIsNoOpDuringReplay(): void
    {
        $this->appendEventWithTurn('run_started', 1, 0, [
            'step_id' => 's0',
            'payload' => ['messages' => []],
        ]);
        // leaf_set and turn_branched events must not change RunState.
        $this->appendEventWithTurn(RunEventTypeEnum::LeafSet->value, 2, 1, [
            'turn_no' => 1,
            'reason' => 'continue',
        ]);
        $this->appendEventWithTurn(RunEventTypeEnum::TurnBranched->value, 3, 1, [
            'turn_no' => 1,
            'parent_turn_no' => null,
            'reason' => 'rewind',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        // Status should remain the same as after run_started (Running)
        self::assertSame(RunStatus::Running, $result->rebuiltState->status);
        self::assertSame(0, $result->rebuiltState->turnNo, 'leaf_set/turn_branched must not advance turn');
    }

    // ── Compaction event replay ────────────────────────────────────────────

    /**
     * Thesis: context_compacted replaces the message accumulator wholesale
     * from payload.messages. Later events (user message) append on top of
     * the compacted checkpoint, proving the replacement is authoritative.
     */
    public function testContextCompactedReplacesMessages(): void
    {
        $user1 = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original message 1']]];
        $user2 = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original message 2']]];

        // Sequence: run_started (with 2 original messages) → context_compacted
        // (replaces with 1 summary) → agent_command_applied (appends new user)
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [$user1, $user2]],
            'step_id' => 'init',
        ]);

        $summaryMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<summary>Compacted content</summary>']], 'metadata' => ['compact_summary' => true]];

        $this->appendEvent(RunEventTypeEnum::ContextCompacted->value, 2, [
            'summary_text' => 'Compacted content',
            'messages' => [$summaryMsg],
            'estimated_tokens_before' => 10000,
            'estimated_tokens_after' => 2000,
            'messages_compacted' => 2,
            'messages_retained' => 0,
            'first_retained_index' => 2,
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'trigger' => 'manual',
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 3, [
            'kind' => 'steer',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'New message after compaction']]],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertCount(2, $result->rebuiltState->messages, 'Should have summary + new user message after compaction');
        self::assertSame('user', $result->rebuiltState->messages[0]->role);
        self::assertTrue(($result->rebuiltState->messages[0]->metadata['compact_summary'] ?? false), 'First message should be compact summary');
        self::assertSame('user', $result->rebuiltState->messages[1]->role);
        self::assertSame('New message after compaction', $result->rebuiltState->messages[1]->content[0]['text']);
        self::assertNull($result->rebuiltState->activeStepId, 'context_compacted must clear activeStepId — compaction is one-shot, no AdvanceRun follows');
        // Status after compaction + steer: the steer command sets Running.
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Steer after compaction sets Running');
    }

    /**
     * Thesis: context_compaction_failed does NOT replace messages.
     * Prior messages survive unchanged, and later events append normally.
     */
    public function testContextCompactionFailedPreservesMessages(): void
    {
        $userMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original message']]];

        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [$userMsg]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 2, [
            'reason' => 'empty_summary',
            'message' => 'Compaction failed: empty summary.',
            'messages_replaced' => false,
            'model' => 'openai/gpt-4.1-mini',
            'trigger' => 'manual',
        ]);

        $this->appendEvent(RunEventTypeEnum::AgentCommandApplied->value, 3, [
            'kind' => 'steer',
            'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Follow-up']]],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertCount(2, $result->rebuiltState->messages, 'Original message should survive + new follow-up');
        self::assertSame('Original message', $result->rebuiltState->messages[0]->content[0]['text']);
        self::assertSame('Follow-up', $result->rebuiltState->messages[1]->content[0]['text']);
    }

    /**
     * Thesis: context_compaction_started does NOT mutate messages
     * but DOES restore activeStepId from payload.step_id so that
     * a subsequent CompactionStepResult is accepted after replay.
     */
    public function testContextCompactionStartedDoesNotMutateMessages(): void
    {
        $userMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]];

        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [$userMsg]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-step-42',
            'trigger' => 'manual',
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertCount(1, $result->rebuiltState->messages, 'Messages should not be mutated by started event');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
        self::assertSame('compaction-step-42', $result->rebuiltState->activeStepId, 'Started event MUST restore activeStepId for result staleness guard');
        self::assertSame(RunStatus::Compacting, $result->rebuiltState->status, 'Started event MUST set status to Compacting to mirror live CompactRunHandler');
    }

    /**
     * Thesis: context_compaction_failed with matching step_id clears activeStepId
     * (emitted by CompactionStepResultHandler for model_error/empty_summary).
     */
    public function testContextCompactionFailedClearsActiveStepIdWhenStepIdMatches(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-X',
            'trigger' => 'manual',
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'model_error',
            'message' => 'Compaction failed: model error.',
            'messages_replaced' => false,
            'step_id' => 'compaction-X',
            'model' => 'openai/gpt-4.1-mini',
            'trigger' => 'manual',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertNull($result->rebuiltState->activeStepId, 'Matching step_id failure must clear activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
        self::assertSame(RunStatus::Completed, $result->rebuiltState->status, 'Manual context_compaction_failed must resolve Compacting → Completed');
    }

    /**
     * Thesis: context_compaction_failed with stale_result reason preserves
     * activeStepId even when step_id matches — the live handler treats
     * stale as non-current, so replay must mirror that.  Resolves
     * Compacting → Running so the state is not stuck in a terminal.
     */
    public function testContextCompactionFailedStaleResultPreservesActiveStepIdWhenStepIdMatches(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-X',
            'trigger' => 'manual',
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        // stale_result with matching step_id — step_id === activeStepId
        // but reason is stale_result; live handler preserves activeStepId.
        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'stale_result',
            'message' => 'Compaction result arrived too late.',
            'messages_replaced' => false,
            'step_id' => 'compaction-X',
            'trigger' => 'manual',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame('compaction-X', $result->rebuiltState->activeStepId, 'Stale result must preserve activeStepId even when step_id matches');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Stale result must resolve Compacting → Running');
    }

    /**
     * Thesis: context_compaction_failed with different step_id preserves activeStepId
     * (stale result for old compaction A when B is active).  Resolves
     * Compacting → Running.
     */
    public function testContextCompactionFailedPreservesActiveStepIdWhenStepIdDiffers(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-B', // B is in-flight
            'trigger' => 'manual',
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        // Stale failure from compaction A arrives — step_id differs from active compaction-B.
        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'stale_result',
            'message' => 'Compaction result arrived too late.',
            'messages_replaced' => false,
            'step_id' => 'compaction-A', // different from active compaction-B
            'trigger' => 'manual',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame('compaction-B', $result->rebuiltState->activeStepId, 'Different step_id failure must preserve current activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved');
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Stale failure with different step_id must resolve Compacting → Running');
    }

    /**
     * Thesis: context_compaction_failed without step_id (structural failure
     * from CompactRunHandler) preserves activeStepId and prior status —
     * the live CompactRunHandler does NOT transition to Compacting for
     * prepare/hook-cancel failures, so replay mirrors that.
     */
    public function testContextCompactionFailedPreservesActiveStepIdWhenNoStepId(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-B',
            'trigger' => 'manual',
            'model' => 'openai/gpt-4.1-mini',
            'thinking_level' => 'low',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        // Structural failure without step_id (from CompactRunHandler).
        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'too_few_messages',
            'message' => 'Compaction failed: too few messages.',
            'messages_replaced' => false,
            'trigger' => 'manual',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame('compaction-B', $result->rebuiltState->activeStepId, 'No step_id failure must preserve activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved');
        self::assertSame(RunStatus::Compacting, $result->rebuiltState->status, 'Structural failure must preserve prior status — Compacting was set by started event');
    }

    /**
     * Thesis: pre-LLM auto context_compacted resolves Compacting → Running.
     * When continue_after_compaction=true, the pre-LLM guard held a pending
     * LLM turn — the run stays Running so the turn can proceed.
     */
    public function testPreLlmAutoContextCompactedResolvesToRunning(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $summaryMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<summary>Auto compacted</summary>']], 'metadata' => ['compact_summary' => true]];

        $this->appendEvent(RunEventTypeEnum::ContextCompacted->value, 3, [
            'summary_text' => 'Auto compacted',
            'messages' => [$summaryMsg],
            'estimated_tokens_before' => 50000,
            'estimated_tokens_after' => 2000,
            'messages_compacted' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'trigger' => 'auto',
            'continue_after_compaction' => true,
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Pre-LLM auto context_compacted must resolve Compacting → Running');
        self::assertNull($result->rebuiltState->activeStepId, 'Completed compaction must clear activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages replaced by compacted checkpoint');
        self::assertTrue(($result->rebuiltState->messages[0]->metadata['compact_summary'] ?? false), 'First message is compact summary');
    }

    /**
     * Thesis: after-turn auto context_compacted resolves Compacting → Completed.
     * When continue_after_compaction=false (default, after-turn maintenance),
     * the run was already terminal — compaction is a housekeeping operation
     * and must NOT auto-continue the conversation.
     */
    public function testAfterTurnAutoContextCompactedResolvesToCompleted(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $summaryMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<summary>After-turn auto</summary>']], 'metadata' => ['compact_summary' => true]];

        $this->appendEvent(RunEventTypeEnum::ContextCompacted->value, 3, [
            'summary_text' => 'After-turn auto',
            'messages' => [$summaryMsg],
            'estimated_tokens_before' => 50000,
            'estimated_tokens_after' => 2000,
            'messages_compacted' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'trigger' => 'auto',
            // No continue_after_compaction → default false.
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Completed, $result->rebuiltState->status, 'After-turn auto context_compacted must resolve Compacting → Completed (maintenance, not continuation)');
        self::assertNull($result->rebuiltState->activeStepId, 'Completed compaction must clear activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages replaced by compacted checkpoint');
        self::assertTrue(($result->rebuiltState->messages[0]->metadata['compact_summary'] ?? false), 'First message is compact summary');
    }

    /**
     * Thesis: pre-LLM auto context_compaction_failed resolves Compacting → Running
     * when step_id matches and reason is not stale_result.  The live handler
     * returns to Running so the run can continue (pre-LLM guard won't re-fire).
     */
    public function testPreLlmAutoContextCompactionFailedResolvesToRunning(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'model_error',
            'message' => 'Compaction failed: model error.',
            'messages_replaced' => false,
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'continue_after_compaction' => true,
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Pre-LLM auto context_compaction_failed must resolve Compacting → Running');
        self::assertNull($result->rebuiltState->activeStepId, 'Matching step_id must clear activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved on failure');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
    }

    /**
     * Thesis: after-turn auto context_compaction_failed resolves Compacting → Completed.
     * When continue_after_compaction=false, the failure is terminal for the
     * compaction lifecycle and the run returns to its prior terminal state
     * rather than auto-continuing.
     */
    public function testAfterTurnAutoContextCompactionFailedResolvesToCompleted(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'model_error',
            'message' => 'Compaction failed: model error.',
            'messages_replaced' => false,
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            // No continue_after_compaction → default false.
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Completed, $result->rebuiltState->status, 'After-turn auto context_compaction_failed must resolve Compacting → Completed');
        self::assertNull($result->rebuiltState->activeStepId, 'Matching step_id must clear activeStepId');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved on failure');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
    }

    /**
     * Thesis: auto context_compaction_failed with stale_result resolves
     * Compacting → Running while preserving activeStepId.  This mirrors
     * the live CompactionStepResultHandler stale_result path which resolves
     * Compacting to Running so the state is not stuck.
     */
    public function testAutoContextCompactionFailedStaleResultResolvesToRunning(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionFailed->value, 3, [
            'reason' => 'stale_result',
            'message' => 'Compaction result arrived too late.',
            'messages_replaced' => false,
            'step_id' => 'compaction-auto',
            'trigger' => 'auto',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame('compaction-auto', $result->rebuiltState->activeStepId, 'Stale result must preserve activeStepId');
        self::assertSame(RunStatus::Running, $result->rebuiltState->status, 'Auto stale_result must resolve Compacting → Running');
        self::assertCount(1, $result->rebuiltState->messages, 'Messages preserved');
        self::assertSame('Original', $result->rebuiltState->messages[0]->content[0]['text']);
    }

    /**
     * Thesis: manual context_compacted resolves Compacting → Completed.
     * Manual /compact is invoked on a finished run; after success the
     * run returns to Completed with compacted messages.
     */
    public function testManualContextCompactedResolvesToCompleted(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'payload' => ['messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Original']]]]],
            'step_id' => 'init',
        ]);

        $this->appendEvent(RunEventTypeEnum::ContextCompactionStarted->value, 2, [
            'step_id' => 'compaction-manual',
            'trigger' => 'manual',
            'estimated_tokens' => 50000,
            'keep_recent_tokens' => 20000,
            'messages_before' => 10,
            'messages_to_summarize' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'prior_summary_present' => false,
        ]);

        $summaryMsg = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '<summary>Manual compacted</summary>']], 'metadata' => ['compact_summary' => true]];

        $this->appendEvent(RunEventTypeEnum::ContextCompacted->value, 3, [
            'summary_text' => 'Manual compacted',
            'messages' => [$summaryMsg],
            'estimated_tokens_before' => 50000,
            'estimated_tokens_after' => 2000,
            'messages_compacted' => 7,
            'messages_retained' => 3,
            'first_retained_index' => 7,
            'trigger' => 'manual',
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        self::assertSame(RunStatus::Completed, $result->rebuiltState->status, 'Manual context_compacted must resolve Compacting → Completed');
        self::assertNull($result->rebuiltState->activeStepId, 'Completed compaction must clear activeStepId');
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

    /**
     * Append an event with an explicit turn number (for branch replay tests).
     *
     * @param array<string, mixed> $payload
     */
    private function appendEventWithTurn(string $type, int $seq, int $turnNo, array $payload): void
    {
        $this->eventStore->append(new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        ));
    }

    // ── Thinking-only assistant message replay ─────────────────────────────

    /**
     * Subject: thinking-only assistant messages (content: null, no
     * tool_calls, details.thinking present) must NOT be replayed as
     * state messages. They were erroneously persisted before
     * ExecuteLlmStepWorker started converting reasoning-only provider
     * responses to errors, and replaying them into the message history
     * causes provider 400 "content or tool_calls must be set".
     */
    public function testThinkingOnlyAssistantNotReplayed(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 'step-init',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::TurnAdvanced->value, 2, [
            'step_id' => 'step-1',
            'turn_no' => 1,
        ]);

        // A thinking-only assistant message: role=assistant, content=null,
        // no tool_calls, but reasoning/thinking in details.
        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, [
            'step_id' => 'step-1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'details' => [
                    'thinking' => 'The user wants me to respond but I ran out of tokens mid-reasoning.',
                ],
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);

        // No assistant message must appear in state messages.
        $messages = $result->rebuiltState->messages;
        self::assertCount(
            0,
            $messages,
            'Thinking-only assistant message must not be replayed into state messages.',
        );
    }

    /**
     * Subject: tool-call-only assistant messages (no text content,
     * but tool_calls present) must still be replayed normally.
     */
    public function testToolCallOnlyAssistantStillReplayed(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 'step-init',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::TurnAdvanced->value, 2, [
            'step_id' => 'step-1',
            'turn_no' => 1,
        ]);

        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, [
            'step_id' => 'step-1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call-1',
                        'name' => 'search',
                        'arguments' => ['query' => 'test'],
                    ],
                ],
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $messages = $result->rebuiltState->messages;
        self::assertCount(1, $messages, 'Tool-call-only assistant message must be replayed.');
        self::assertSame('assistant', $messages[0]->role);
        self::assertSame('call-1', $messages[0]->metadata['tool_calls'][0]['id']);
    }

    /**
     * Subject: text-bearing assistant messages with thinking must
     * still be replayed normally (the thinking is in details, but
     * content carries text).
     */
    public function testTextWithThinkingAssistantStillReplayed(): void
    {
        $this->appendEvent(RunEventTypeEnum::RunStarted->value, 1, [
            'step_id' => 'step-init',
            'payload' => ['messages' => []],
        ]);

        $this->appendEvent(RunEventTypeEnum::TurnAdvanced->value, 2, [
            'step_id' => 'step-1',
            'turn_no' => 1,
        ]);

        $this->appendEvent(RunEventTypeEnum::LlmStepCompleted->value, 3, [
            'step_id' => 'step-1',
            'assistant_message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Here is the answer.']],
                'details' => [
                    'thinking' => 'I should explain this carefully.',
                ],
            ],
        ]);

        $state = RunState::queued($this->runId);
        $result = $this->service->rebuildIfStale($state, $this->runId);

        self::assertTrue($result->rebuilt);
        $messages = $result->rebuiltState->messages;
        self::assertCount(1, $messages, 'Text+thinking message must be replayed.');
        self::assertSame('assistant', $messages[0]->role);
        self::assertSame('Here is the answer.', $messages[0]->content[0]['text']);
    }
}
