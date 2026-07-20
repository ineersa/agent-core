<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-replay E2E: SafeGuard RequireApproval uses canonical WaitingHuman.
 */
#[Group('controller-replay')]
final class SafeGuardApprovalControllerReplayTest extends ControllerReplayE2eTestCase
{
    private string $targetOutsidePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->targetOutsidePath = \dirname($this->tempDir).'/sg-'.$this->sessionId.'.txt';
        @unlink($this->targetOutsidePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->targetOutsidePath);
        parent::tearDown();
    }

    public function testWriteOutsideCwdAllowViaCanonicalHumanInput(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_start_'.uniqid(),
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call the write tool with path ../sg-'.$this->sessionId
                    .'.txt and content hello. After the tool succeeds, answer exactly done.',
            ],
        ]);

        $preAnswerEvents = $this->collectEventsUntil('human_input.requested', 20.0);
        $byTypePre = $this->indexByType($preAnswerEvents);
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

        $postEvents = $this->collectEventsUntilToolCompleted('write', 25.0);
        $all = array_merge($preAnswerEvents, $postEvents);
        $byType = $this->indexByType($all);

        $this->assertArrayNotHasKey('tool_question.requested', $byType, $this->collectDiagnostics($all));
        $this->assertFileExists($this->targetOutsidePath, $this->collectDiagnostics($all));
        $this->assertSame('hello', trim((string) file_get_contents($this->targetOutsidePath)));

        $writeStarts = array_values(array_filter(
            $byType['tool_execution.started'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_name'] ?? null) === 'write',
        ));
        $this->assertCount(1, $writeStarts, $this->collectDiagnostics($all));
        $this->assertSame($toolCallId, $writeStarts[0]['payload']['tool_call_id'] ?? null);

        // Terminal completion must match the SAME tool_call_id that requested human input.
        // Pre-fix bug: suspension + terminal ToolCallResult shared ExecuteToolCall idempotency
        // key so RunMessageProcessor dropped the terminal result after Allow.
        $writeCompleted = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === $toolCallId,
        ));
        $this->assertNotEmpty(
            $writeCompleted,
            'Allow must deliver terminal tool_execution.completed for the suspended write call. '
            .$this->collectDiagnostics($all),
        );
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($all));
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($all));
    }

    public function testWriteOutsideCwdBlockHasNoFilesystemSideEffect(): void
    {
        // Same outside path as allow-once: fixtures always emit that write path.
        @unlink($this->targetOutsidePath);

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_start_'.uniqid(),
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call the write tool with path ../sg-'.$this->sessionId
                    .'.txt and content hello. After tools finish, answer exactly done.',
            ],
        ]);

        $pre = $this->collectEventsUntil('human_input.requested', 20.0);
        $byTypePre = $this->indexByType($pre);
        $runStarted = $byTypePre['run.started'][0] ?? null;
        $this->assertNotNull($runStarted, $this->collectDiagnostics($pre));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $hitl = $byTypePre['human_input.requested'][0];
        $questionId = (string) ($hitl['payload']['question_id'] ?? '');

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_answer_'.uniqid(),
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $questionId,
                'answer' => '❌ Deny',
            ],
        ]);

        $this->collectEventsUntil('run.completed', 25.0);
        $this->assertFileDoesNotExist($this->targetOutsidePath);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-sg-approval';
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

        $firstFixture = [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'reasoning' => 'off',
            'deltas' => $buildDeltas('call_sg_1'),
            'stop_reason' => 'tool_call',
        ];

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
}
