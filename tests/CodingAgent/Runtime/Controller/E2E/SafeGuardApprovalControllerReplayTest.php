<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-replay E2E test proving SafeGuard cross-process approval works
 * in the DEFAULT 'process' transport (separate messenger consumers).
 *
 * Flow:
 *   1. LLM calls write tool with a path OUTSIDE the project CWD
 *   2. SafeGuard interrupts with RequireApproval → WaitingHuman
 *   3. Test captures question_id from human_input.requested event
 *   4. Test answers "Allow once" via answer_human JSONL command
 *   5. SafeGuardApprovalCommitSubscriber writes approved decision to the
 *      shared cache.approvals pool (cross-process, via CachedApprovalLedger)
 *   6. AdvanceRun retries the tool call; ExtensionToolHookEventSubscriber
 *      checks the shared cache before blocking → ALLOWS the execution
 *   7. Write tool executes, creating the file on disk
 *
 * This exercises the REAL multi-process topology (run_control consumer
 * handles answer commit, tool consumer handles execution) with the shared
 * DBAL-backed cache as the cross-process glue.
 *
 * @see ControllerReplayE2eTestCase
 * @see CachedApprovalLedger
 * @see SafeGuardApprovalCommitSubscriber
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
        // tempDir = var/tmp/test-sg-approval-{uuid}/
        // The fixture writes to ../sg-{sessionId}.txt
        // so the resolved path is dirname(tempDir)/sg-{sessionId}.txt
        $this->targetOutsidePath = \dirname($this->tempDir).'/sg-'.$this->sessionId.'.txt';

        // Ensure file does not exist before test
        @unlink($this->targetOutsidePath);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        // The path the LLM generates: ../sg-{sessionId}.txt
        // We splice the sessionId directly at clean JSON boundaries so
        // the assembled partial_json produces exactly:
        //   {"path":"../sg-{sessionId}.txt","content":"hello"}
        $sessionId = $this->sessionId;
        $path = '../sg-'.$this->sessionId.'.txt';

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
        // SafeGuard intercepts this and returns RequireApproval.
        $firstFixture = [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'reasoning' => 'off',
            'deltas' => $buildDeltas('call_sg_1'),
            'stop_reason' => 'tool_call',
        ];

        // Second LLM call (retry after user answers "Allow once"):
        // Returns the same write tool call. This time SafeGuard allows it
        // because ExtensionToolHookEventSubscriber checks the shared cache
        // before blocking with RequireApproval.
        $secondFixture = [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'reasoning' => 'off',
            'deltas' => $buildDeltas('call_sg_2'),
            'stop_reason' => 'tool_call',
        ];

        return [$firstFixture, $secondFixture];
    }

    /**
     * Ensure SafeGuard uses RequireApproval (not auto-deny) in the spawned
     * controller subprocess. The real process transport sets
     * HATFIELD_APPROVAL_CHANNEL=controller (see JsonlProcessAgentSessionClient.php:413);
     * without it SafeGuard defaults to autoDenyInNoninteractive which never
     * emits human_input.requested.
     *
     * @return array<string, string>
     */
    protected function replayExtraEnv(): array
    {
        return [
            'HATFIELD_APPROVAL_CHANNEL' => 'controller',
        ];
    }

    /**
     * Prove "Allow once" works across processes: SafeGuard blocks the
     * first write, user approves, the retry write succeeds.
     */
    public function testWriteOutsideCwdAllowOnceAcrossProcessBoundary(): void
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

        // Collect events until SafeGuard interrupts via human_input.requested.
        // This also gives us run.started (for runId).
        $preApprovalEvents = $this->collectEventsUntil('human_input.requested', 8.0);
        $byTypePre = $this->indexByType($preApprovalEvents);

        // Extract runId from run.started
        $runStarted = $byTypePre['run.started'][0] ?? null;
        $this->assertNotNull($runStarted, 'Expected run.started before human_input.requested. '
            .$this->collectDiagnostics($preApprovalEvents));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        // Extract question_id from human_input.requested
        $humanInputEvents = $byTypePre['human_input.requested'] ?? [];
        $this->assertNotEmpty($humanInputEvents,
            'Expected human_input.requested when SafeGuard blocks an outside-CWD write. '
            .$this->collectDiagnostics($preApprovalEvents));
        $questionId = (string) ($humanInputEvents[0]['payload']['question_id'] ?? '');
        $this->assertNotEmpty($questionId,
            'human_input.requested must carry a question_id. '
            .$this->collectDiagnostics($preApprovalEvents));

        // Simulate the human answering "Allow once"
        $answerCmdId = 'cmd_answer_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $answerCmdId,
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $questionId,
                'answer' => 'Allow once',
            ],
        ]);

        // Wait for the write tool to complete (proves SafeGuard allowed it
        // on the retry because the approved decision reached the shared cache
        // across the run_control → tool consumer process boundary).
        $events = $this->collectEventsUntilToolCompleted('write', 12.0);
        $byType = $this->indexByType($events);

        // Verify tool execution happened
        $this->assertArrayHasKey('tool_execution.started', $byType,
            'write tool must start after approval. '
            .$this->collectDiagnostics($events));
        $this->assertSame('write',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'The LLM must call the write tool. '
            .$this->collectDiagnostics($events));
        $this->assertArrayHasKey('tool_execution.completed', $byType,
            'write tool must complete after approval. '
            .$this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType,
            'write tool must not fail after approval. '
            .$this->collectDiagnostics($events));

        // Prove the write actually happened on disk (outside CWD)
        $this->assertFileExists($this->targetOutsidePath,
            'The write tool must create the file outside CWD after approval. '
            .$this->collectDiagnostics($events));
        $this->assertSame('hello', trim((string) file_get_contents($this->targetOutsidePath)),
            'File content must match what the LLM wrote. '
            .$this->collectDiagnostics($events));
    }
}
