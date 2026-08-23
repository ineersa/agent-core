<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-replay proof for SafeGuard outside-CWD write approval.
 *
 * Unit coverage already owns classification, answer mapping, suspension state,
 * and answer_human handling. This case exercises the real controller JSONL +
 * Messenger wiring only:
 *
 *   write outside CWD → human_input.requested (not tool_question.requested)
 *   → answer_human Allow → same tool_call_id completes → file artifact
 *
 * Sequential second-approval / deny paths remain unit-covered
 * (SafeGuardToolCallHookTest, ToolCallHumanInputSuspensionTest).
 * Live provider Allow remains an accepted gap (deleted SafeGuardAllowLiveE2eTest).
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplaySafeGuardApprovalTest extends ControllerReplayE2eTestCase
{
    private const string TOOL_CALL_ID = 'call_sg_write_1';
    private const string FILE_CONTENT = 'hello';

    private string $targetOutsidePath = '';

    private string $relativeWritePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->relativeWritePath = '../sg-'.$this->sessionId.'.txt';
        $this->targetOutsidePath = \dirname($this->tempDir).'/sg-'.$this->sessionId.'.txt';
        @unlink($this->targetOutsidePath);
        // Fixtures need session-scoped path; rebuild after setUp assigns sessionId/tempDir.
        $this->replayFixtures = $this->replayFixtures();
    }

    protected function tearDown(): void
    {
        if ('' !== $this->targetOutsidePath) {
            @unlink($this->targetOutsidePath);
        }
        parent::tearDown();
    }

    public function testWriteOutsideCwdAllowCompletesExactToolCall(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call write once with path '.$this->relativeWritePath
                    .' and content '.self::FILE_CONTENT.'.',
            ],
        ]);

        // Controller → tool consumer → SafeGuard suspend → run_control admit
        // is multi-hop; keep a tight safety cap under the 10s case ceiling.
        $preAnswer = $this->collectEventsUntil('human_input.requested', 8.0);
        $preByType = $this->indexByType($preAnswer);
        $this->assertStartRunAcked($preAnswer, $startCmdId);
        $this->assertArrayHasKey(
            'human_input.requested',
            $preByType,
            'Outside-CWD write must emit canonical human_input.requested. '
            .$this->collectDiagnostics($preAnswer),
        );
        $this->assertArrayNotHasKey(
            'tool_question.requested',
            $preByType,
            'SafeGuard approvals must not use legacy tool_question.requested. '
            .$this->collectDiagnostics($preAnswer),
        );

        $runStarted = $preByType['run.started'][0] ?? null;
        $this->assertIsArray($runStarted, $this->collectDiagnostics($preAnswer));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $hitl = $preByType['human_input.requested'][0];
        $questionId = (string) ($hitl['payload']['question_id'] ?? '');
        $toolCallId = (string) ($hitl['payload']['tool_call_id'] ?? '');
        $this->assertNotSame('', $questionId, $this->collectDiagnostics($preAnswer));
        $this->assertSame(self::TOOL_CALL_ID, $toolCallId, $this->collectDiagnostics($preAnswer));
        $this->assertSame('write', $hitl['payload']['tool_name'] ?? null, $this->collectDiagnostics($preAnswer));

        $answerCmdId = 'cmd_answer_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $answerCmdId,
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $questionId,
                'answer' => '✅ Allow',
            ],
        ]);

        $post = $this->collectEventsUntil(
            'tool_execution.completed',
            8.0,
            static fn (array $event): bool => ($event['type'] ?? '') === 'tool_execution.completed'
                && ($event['payload']['tool_call_id'] ?? null) === self::TOOL_CALL_ID,
        );
        $all = array_merge($preAnswer, $post);
        $byType = $this->indexByType($all);

        $this->assertTrue(
            $this->foundAck($post, $answerCmdId),
            'answer_human must be acked. '.$this->collectDiagnostics($post),
        );
        $this->assertArrayNotHasKey(
            'tool_question.requested',
            $byType,
            $this->collectDiagnostics($all),
        );
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($all));
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($all));

        $writeStarts = array_values(array_filter(
            $byType['tool_execution.started'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === self::TOOL_CALL_ID,
        ));
        $this->assertCount(1, $writeStarts, $this->collectDiagnostics($all));
        $this->assertSame('write', $writeStarts[0]['payload']['tool_name'] ?? null);

        $writeCompleted = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === self::TOOL_CALL_ID,
        ));
        $this->assertCount(
            1,
            $writeCompleted,
            'Allow must deliver exactly one tool_execution.completed for the suspended write call. '
            .$this->collectDiagnostics($all),
        );

        $this->assertFileExists($this->targetOutsidePath, $this->collectDiagnostics($all));
        $this->assertSame(
            self::FILE_CONTENT,
            trim((string) file_get_contents($this->targetOutsidePath)),
            $this->collectDiagnostics($all),
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-sg-approval';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $path = '' !== $this->relativeWritePath
            ? $this->relativeWritePath
            : '../sg-pending.txt';

        return [
            [
                '$schema' => 'Synthetic controller replay — SafeGuard outside-CWD write',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Deterministic write tool call that requires SafeGuard approval.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'stop_reason' => 'tool_call',
                'deltas' => [
                    ['type' => 'tool_call_start', 'id' => self::TOOL_CALL_ID, 'name' => 'write'],
                    [
                        'type' => 'tool_input_delta',
                        'id' => self::TOOL_CALL_ID,
                        'name' => 'write',
                        'partial_json' => '{"path":"'.$path.'","content":"'.self::FILE_CONTENT.'"}',
                    ],
                    [
                        'type' => 'tool_call_complete',
                        'tool_calls' => [
                            [
                                'id' => self::TOOL_CALL_ID,
                                'name' => 'write',
                                'arguments' => [
                                    'path' => $path,
                                    'content' => self::FILE_CONTENT,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                '$schema' => 'Synthetic controller replay — post-allow assistant turn',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Absorb the post-tool LLM turn after Allow completes the write.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'stop_reason' => 'stop',
                'deltas' => [
                    ['type' => 'text', 'content' => 'done'],
                ],
            ],
        ];
    }

    protected function replayExtraEnv(): array
    {
        return [
            // Interactive approval channel so SafeGuard emits RequireApproval instead of auto-deny.
            'HATFIELD_APPROVAL_CHANNEL' => 'controller',
        ];
    }
}
