<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live proof: SafeGuard Allow must resume the exact write call and deliver
 * terminal tool_execution.completed for the same tool_call_id.
 *
 * Reproduces the stuck-run topology where suspension and terminal ToolCallResult
 * previously shared ExecuteToolCall::idempotencyKey(), so RunMessageProcessor
 * dropped the terminal result after human_input Allow.
 *
 * Isolation layout keeps llama-proxy cache keys stable across runs:
 * - parent creates unique isolation root under var/tmp/
 * - controller cwd is nested fixed `$isolationRoot/cwd`
 * - write target is constant relative path `../sg-live-allow.txt` → isolation root
 * Prompt text therefore never embeds sessionId or random temp paths.
 */
#[Group('llm-real')]
final class SafeGuardAllowLiveE2eTest extends ControllerE2eTestCase
{
    private string $isolationRoot = '';

    private string $targetOutsidePath = '';

    protected function tearDown(): void
    {
        if ('' !== $this->targetOutsidePath) {
            @unlink($this->targetOutsidePath);
        }

        // Parent removes nested controller cwd ($this->tempDir) and process trees.
        parent::tearDown();

        if ('' !== $this->isolationRoot) {
            TestDirectoryIsolation::removeDirectory($this->isolationRoot);
            $this->isolationRoot = '';
        }
    }

    public function testWriteOutsideCwdAllowCompletesExactToolCall(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // Constant relative path: resolves to isolation root, never embeds session/uuid.
        $relativePath = '../sg-live-allow.txt';
        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                // Unique first-user prompt tag for llama-proxy cache key isolation.
                // Path and content are fixed so warm gate runs add zero cache entries.
                'prompt' => '[llm-real:safeguard-allow-write] Use exactly one tool call: tool name `write` with arguments '
                    .'`{ "path": "'.$relativePath.'", "content": "live-allow" }`. '
                    .'Do not use any other tool. After the tool succeeds, answer exactly `done`.',
            ],
        ]);

        $preAnswerEvents = $this->collectEventsUntil('human_input.requested', $this->liveLlmToolWaitTimeout());
        $byTypePre = $this->indexByType($preAnswerEvents);
        $this->assertStartRunAcked($preAnswerEvents, $startCmdId);
        $this->assertArrayHasKey('human_input.requested', $byTypePre, $this->collectDiagnostics($preAnswerEvents));
        $this->assertArrayNotHasKey('tool_question.requested', $byTypePre, $this->collectDiagnostics($preAnswerEvents));

        $runStarted = $byTypePre['run.started'][0] ?? null;
        $this->assertNotNull($runStarted, $this->collectDiagnostics($preAnswerEvents));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $hitl = $byTypePre['human_input.requested'][0];
        $questionId = (string) ($hitl['payload']['question_id'] ?? '');
        $this->assertNotSame('', $questionId, $this->collectDiagnostics($preAnswerEvents));
        $toolCallId = (string) ($hitl['payload']['tool_call_id'] ?? '');
        $this->assertNotSame('', $toolCallId, $this->collectDiagnostics($preAnswerEvents));

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_answer_'.uniqid(),
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $questionId,
                'answer' => '✅ Allow',
            ],
        ]);

        // Wait for the SAME tool_call_id to complete (not merely any write completion).
        $postEvents = $this->collectEventsUntilToolCompleted('write', $this->liveLlmToolWaitTimeout());
        $all = array_merge($preAnswerEvents, $postEvents);
        $byType = $this->indexByType($all);

        $this->assertArrayNotHasKey('tool_question.requested', $byType, $this->collectDiagnostics($all));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($all));
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($all));

        $matchingCompleted = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === $toolCallId,
        ));
        $this->assertNotEmpty(
            $matchingCompleted,
            'Allow must complete the exact suspended write tool_call_id. '
            .$this->collectDiagnostics($all),
        );

        $this->assertFileExists($this->targetOutsidePath, $this->collectDiagnostics($all));
        $this->assertSame('live-allow', trim((string) file_get_contents($this->targetOutsidePath)));
    }

    protected function createIsolatedProjectDir(): void
    {
        // Parent setUp already assigned a unique project temp dir to $this->tempDir.
        // Keep that as isolation root, then re-point tempDir at a nested fixed cwd so
        // the controller's absolute cwd is unique while the user prompt path stays
        // the constant relative string `../sg-live-allow.txt`.
        $this->isolationRoot = $this->tempDir;
        $nestedCwd = $this->isolationRoot.'/cwd';
        TestDirectoryIsolation::ensureDirectory($nestedCwd, 0o777);
        $this->tempDir = $nestedCwd;

        parent::createIsolatedProjectDir();

        $this->targetOutsidePath = $this->isolationRoot.'/sg-live-allow.txt';
        @unlink($this->targetOutsidePath);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-sg-allow-live';
    }

    protected function controllerSubprocessEnv(): array
    {
        return [
            'HATFIELD_APPROVAL_CHANNEL' => 'controller',
            // Live path needs room for LLM + SafeGuard suspend + resume tool hop.
            'HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '60',
        ];
    }

    protected function liveLlmToolWaitTimeout(): float
    {
        // Suspension + answer + second tool execution exceeds default 12s under load.
        return 25.0;
    }
}
