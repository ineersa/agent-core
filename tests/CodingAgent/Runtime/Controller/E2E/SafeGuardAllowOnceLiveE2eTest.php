<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live proof: SafeGuard Allow once must resume the exact write call and deliver
 * terminal tool_execution.completed for the same tool_call_id.
 *
 * Reproduces the stuck-run topology where suspension and terminal ToolCallResult
 * previously shared ExecuteToolCall::idempotencyKey(), so RunMessageProcessor
 * dropped the terminal result after human_input Allow once.
 */
#[Group('llm-real')]
final class SafeGuardAllowOnceLiveE2eTest extends ControllerE2eTestCase
{
    private string $targetOutsidePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->targetOutsidePath = \dirname($this->tempDir).'/sg-live-'.$this->sessionId.'.txt';
        @unlink($this->targetOutsidePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->targetOutsidePath);
        parent::tearDown();
    }

    public function testWriteOutsideCwdAllowOnceCompletesExactToolCall(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $relativePath = '../sg-live-'.$this->sessionId.'.txt';
        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                // Unique first-user prompt tag for llama-proxy cache key isolation.
                'prompt' => '[llm-real:safeguard-allow-once-write] Use exactly one tool call: tool name `write` with arguments '
                    .'`{ "path": "'.$relativePath.'", "content": "live-allow-once" }`. '
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
                'answer' => '✅ Allow once',
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
            'Allow once must complete the exact suspended write tool_call_id. '
            .$this->collectDiagnostics($all),
        );

        $this->assertFileExists($this->targetOutsidePath, $this->collectDiagnostics($all));
        $this->assertSame('live-allow-once', trim((string) file_get_contents($this->targetOutsidePath)));
    }

    protected function tempDirPrefix(): string
    {
        return 'test-sg-allow-once-live';
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
