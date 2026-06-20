<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-replay E2E test proving SafeGuard blocking-poll approval works
 * in the DEFAULT 'process' transport (separate messenger consumers).
 *
 * Flow (generic blocking-poll approach, no SafeGuard-specific infra):
 *   1. LLM calls write tool with a path OUTSIDE the project CWD
 *   2. SafeGuard intercepts with RequireApproval
 *   3. ExtensionToolHookEventSubscriber creates a generic ToolQuestion
 *      (kind=approval) and BLOCKS in a polling loop
 *   4. Controller ToolQuestionPoller emits tool_question.requested
 *   5. Test injects the answer via answer_tool_question JSONL command
 *      with answer="Allow once" (generic kind)
 *   6. Blocking poll returns "Allow once" → resolveApprovalAnswer→Allow
 *   7. Write tool creates the file on disk (no retry needed, no extra LLM turn)
 *
 * @see ControllerReplayE2eTestCase
 * @see ExtensionToolHookEventSubscriber
 */
#[Group('controller-replay')]
final class SafeGuardApprovalControllerReplayTest extends ControllerReplayE2eTestCase
{
    private string $targetOutsidePath = '';

    protected function tempDirPrefix(): string
    {
        return 'test-sg-approval';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Compute the absolute path of the expected target file.
        // The fixture writes to ../sg-{sessionId}.txt
        // Since tempDir = var/tmp/test-sg-approval-{uuid}/,
        // the resolved path is dirname(tempDir)/sg-{sessionId}.txt
        $this->targetOutsidePath = \dirname($this->tempDir).'/sg-'.$this->sessionId.'.txt';

        // Ensure file does not exist before test
        @unlink($this->targetOutsidePath);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $path = '../sg-'.$this->sessionId.'.txt';
        $sessionId = $this->sessionId;

        $buildDeltas = static function (string $callId) use ($sessionId, $path): array {
            return [
                ['type' => 'tool_call_start', 'id' => $callId, 'name' => 'write'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '{"path":"../sg'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '-'.$sessionId],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '.txt","content":"h'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => 'ello"}'],
                ['type' => 'tool_call_complete', 'tool_calls' => [
                    ['id' => $callId, 'name' => 'write', 'arguments' => ['path' => $path, 'content' => 'hello']],
                ]],
            ];
        };

        // First LLM call: returns a write tool call with path outside CWD.
        $firstFixture = [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'reasoning' => 'off',
            'deltas' => $buildDeltas('call_sg_1'),
            'stop_reason' => 'tool_call',
        ];

        // Second LLM call (after the blocking-poll returns and tool executes):
        // the LLM sees the real write result and returns text "done".
        $secondFixture = [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'reasoning' => 'off',
            'deltas' => [
                ['type' => 'text', 'content' => 'done'],
            ],
            'stop_reason' => 'stop',
        ];

        return [$firstFixture, $secondFixture];
    }

    protected function replayExtraEnv(): array
    {
        return [
            'HATFIELD_APPROVAL_CHANNEL' => 'controller',
        ];
    }

    /**
     * Prove the blocking-poll SafeGuard approval flow: SafeGuard creates a
     * ToolQuestion and blocks, test answers via answer_tool_question, the
     * write tool executes and creates the file outside CWD.
     *
     * The test uses two collection phases:
     *   1. collectEventsUntilPinpoint — waits for tool_question.requested,
     *      sends the answer, then continues collecting events within a
     *      single timeout window.
     *   2. collectEventsUntilToolCompleted — waits for the write tool's
     *      tool_execution.completed event with generous timeout.
     */
    public function testWriteOutsideCwdAllowOnceViaBlockingPoll(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call the write tool with path ../sg-'.$this->sessionId
                    .'.txt and content hello. After the tool succeeds, answer exactly done.',
            ],
        ]);

        // Phase 1: Collect events until tool_question.requested is emitted.
        // This waits for the subscriber to create a ToolQuestion and the
        // controller's ToolQuestionPoller to pick it up.
        $preAnswerEvents = $this->collectEventsUntil('tool_question.requested', 20.0);
        $byTypePre = $this->indexByType($preAnswerEvents);

        // Verify NO human_input.requested (the old interrupt flow is gone)
        $this->assertArrayNotHasKey('human_input.requested', $byTypePre,
            'Blocking-poll approach must NOT produce human_input.requested. '
            .$this->collectDiagnostics($preAnswerEvents));

        // tool_execution.started is emitted when the LLM step commits
        // ToolExecutionStart events — BEFORE the tool actually executes.
        // It should appear in the pre-answer events.
        $this->assertArrayHasKey('tool_execution.started', $byTypePre,
            'tool_execution.started should appear from the first LLM turn. '
            .$this->collectDiagnostics($preAnswerEvents));
        $this->assertSame('write',
            $byTypePre['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'The first LLM turn must call the write tool. '
            .$this->collectDiagnostics($preAnswerEvents));

        // Extract runId
        $runStarted = $byTypePre['run.started'][0] ?? null;
        $this->assertNotNull($runStarted,
            'Expected run.started before tool_question.requested. '
            .$this->collectDiagnostics($preAnswerEvents));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        // Verify tool_question.requested event
        $toolQuestionEvents = $byTypePre['tool_question.requested'] ?? [];
        $this->assertNotEmpty($toolQuestionEvents,
            'Expected tool_question.requested when SafeGuard blocks outside-CWD write. '
            .$this->collectDiagnostics($preAnswerEvents));

        $tqPayload = $toolQuestionEvents[0]['payload'] ?? [];
        $requestId = (string) ($tqPayload['request_id'] ?? '');
        $this->assertNotEmpty($requestId,
            'tool_question.requested must carry a request_id. '
            .$this->collectDiagnostics($preAnswerEvents));
        $this->assertSame('approval', $tqPayload['kind'] ?? '',
            'tool_question.requested kind must be generic approval. '
            .$this->collectDiagnostics($preAnswerEvents));

        // Phase 2: Send the answer and collect events until tool completes.
        $answerCmdId = 'cmd_answer_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $answerCmdId,
            'type' => 'answer_tool_question',
            'runId' => $this->runId,
            'payload' => [
                'request_id' => $requestId,
                'answer' => '✅ Allow once',
                'kind' => 'approval',
            ],
        ]);

        // Wait for the write tool to complete. The blocking poll runs in the
        // tool consumer: when AnswerToolQuestionHandler writes the answer to
        // the shared SQLite DB, the poll returns the icon-bearing label and the real
        // tool handler executes.
        $events = $this->collectEventsUntilToolCompleted('write', 30.0);
        $byType = $this->indexByType($events);

        // Verify the write tool completed successfully (not failed)
        $this->assertArrayHasKey('tool_execution.completed', $byType,
            'write tool must complete after approval. '
            .$this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType,
            'write tool must not fail after approval. '
            .$this->collectDiagnostics($events));

        // Verify the completed event has is_error=false
        $completedPayload = $byType['tool_execution.completed'][0]['payload'] ?? [];
        $this->assertFalse($completedPayload['is_error'] ?? true,
            'write tool must complete without error. '
            .$this->collectDiagnostics($events));

        // Prove the write actually happened on disk (outside CWD)
        $this->assertFileExists($this->targetOutsidePath,
            'The write tool must create the file outside CWD after approval. '
            .$this->collectDiagnostics($events));
        $this->assertSame('hello', trim((string) file_get_contents($this->targetOutsidePath)),
            'File content must match what the LLM wrote. '
            .$this->collectDiagnostics($events));
    }

    /**
     * Same flow as testWriteOutsideCwdAllowOnceViaBlockingPoll but sends the
     * answer_tool_question WITHOUT a kind field. With schema-driven routing this
     * is the NORMAL case — the handler looks up the stored ToolQuestion by
     * request_id and routes by schema type. The kind field in the answer command
     * is optional (used as fallback only if the stored question is not found).
     *
     * This proves the schema-driven routing works regardless of whether the
     * caller sends a kind: the stored question's enum schema routes to
     * answerWithText, and the blocking poll returns the answer.
     */
    public function testWriteOutsideCwdAllowOnceViaBlockingPollWithoutKind(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call the write tool with path ../sg-'.$this->sessionId
                    .'.txt and content hello. After the tool succeeds, answer exactly done.',
            ],
        ]);

        // Phase 1: Collect events until tool_question.requested is emitted.
        $preAnswerEvents = $this->collectEventsUntil('tool_question.requested', 20.0);
        $byTypePre = $this->indexByType($preAnswerEvents);

        // Verify NO human_input.requested (old interrupt flow)
        $this->assertArrayNotHasKey('human_input.requested', $byTypePre,
            'Blocking-poll must NOT produce human_input.requested. '
            .$this->collectDiagnostics($preAnswerEvents));

        // Verify tool_execution.started
        $this->assertArrayHasKey('tool_execution.started', $byTypePre,
            'tool_execution.started should appear. '
            .$this->collectDiagnostics($preAnswerEvents));

        // Extract runId
        $runStarted = $byTypePre['run.started'][0] ?? null;
        $this->assertNotNull($runStarted, 'Expected run.started. '
            .$this->collectDiagnostics($preAnswerEvents));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        // Get request_id from tool_question.requested
        $toolQuestionEvents = $byTypePre['tool_question.requested'] ?? [];
        $this->assertNotEmpty($toolQuestionEvents,
            'Expected tool_question.requested. '
            .$this->collectDiagnostics($preAnswerEvents));
        $tqPayload = $toolQuestionEvents[0]['payload'] ?? [];
        $requestId = (string) ($tqPayload['request_id'] ?? '');
        $this->assertNotEmpty($requestId,
            'tool_question.requested must carry request_id. '
            .$this->collectDiagnostics($preAnswerEvents));
        $this->assertSame('approval', $tqPayload['kind'] ?? '',
            'tool_question.requested kind must be generic approval. '
            .$this->collectDiagnostics($preAnswerEvents));

        // Phase 2: Send answer WITHOUT kind — this is the regression test.
        // The server-side handler must infer kind from the stored ToolQuestion.
        $answerCmdId = 'cmd_answer_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $answerCmdId,
            'type' => 'answer_tool_question',
            'runId' => $this->runId,
            'payload' => [
                'request_id' => $requestId,
                'answer' => '✅ Allow once',
                // NO 'kind' field — testing server-side inference!
            ],
        ]);

        // Wait for the write tool to complete.
        $events = $this->collectEventsUntilToolCompleted('write', 30.0);
        $byType = $this->indexByType($events);

        // Verify the write tool completed successfully
        $this->assertArrayHasKey('tool_execution.completed', $byType,
            'write tool must complete after approval (kind-inferred path). '
            .$this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType,
            'write tool must not fail after approval. '
            .$this->collectDiagnostics($events));

        // Verify no error
        $completedPayload = $byType['tool_execution.completed'][0]['payload'] ?? [];
        $this->assertFalse($completedPayload['is_error'] ?? true,
            'write tool must complete without error. '
            .$this->collectDiagnostics($events));

        // Prove the write actually happened on disk (outside CWD)
        $this->assertFileExists($this->targetOutsidePath,
            'The write tool must create the file outside CWD after approval (kind-inferred). '
            .$this->collectDiagnostics($events));
        $this->assertSame('hello', trim((string) file_get_contents($this->targetOutsidePath)),
            'File content must match what the LLM wrote. '
            .$this->collectDiagnostics($events));
    }
}
