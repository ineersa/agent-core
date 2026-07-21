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

    /**
     * Scenario selects fixture sequence before spawnController().
     * Set on the test method, then recompute $this->replayFixtures.
     */
    private string $scenario = 'write';

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

    /**
     * Issue #259: Allow on first outside-CWD edit must not authorize a second
     * distinct edit to the same path. Exact-call continuation only.
     */
    public function testSequentialOutsideCwdEditsRequireIndependentApproval(): void
    {
        $this->scenario = 'edit_twice';
        file_put_contents($this->targetOutsidePath, "before\n");
        $this->assertSame("before\n", (string) file_get_contents($this->targetOutsidePath));
        $this->replayFixtures = $this->replayFixtures();

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_start_'.uniqid(),
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call edit twice on ../sg-'.$this->sessionId
                    .'.txt with the provided patches. After tools finish, answer exactly done.',
            ],
        ]);

        $pre1 = $this->collectEventsUntil('human_input.requested', 20.0);
        $byType1 = $this->indexByType($pre1);
        $this->assertArrayHasKey('human_input.requested', $byType1, $this->collectDiagnostics($pre1));
        $this->assertArrayNotHasKey('tool_question.requested', $byType1, $this->collectDiagnostics($pre1));

        $runStarted = $byType1['run.started'][0] ?? null;
        $this->assertNotNull($runStarted, $this->collectDiagnostics($pre1));
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $hitl1 = $byType1['human_input.requested'][0];
        $q1 = (string) ($hitl1['payload']['question_id'] ?? '');
        $tc1 = (string) ($hitl1['payload']['tool_call_id'] ?? '');
        $this->assertNotSame('', $q1, $this->collectDiagnostics($pre1));
        $this->assertSame('call_sg_edit_1', $tc1, $this->collectDiagnostics($pre1));
        $this->assertSame('edit', $hitl1['payload']['tool_name'] ?? null, $this->collectDiagnostics($pre1));

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_answer1_'.uniqid(),
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $q1,
                'answer' => '✅ Allow',
            ],
        ]);

        // Wait for the SECOND human_input in one collector window. Stopping at
        // tool_execution.completed can drop a same-batch human_input.requested
        // for call_sg_edit_2 (parseBuffer already consumed those lines).
        $mid = $this->collectEventsUntil('human_input.requested', 30.0);
        $allMid = array_merge($pre1, $mid);
        $byTypeMid = $this->indexByType($allMid);

        $firstCompleted = array_values(array_filter(
            $byTypeMid['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === 'call_sg_edit_1',
        ));
        $this->assertNotEmpty(
            $firstCompleted,
            'First allowed edit must complete before second approval. '.$this->collectDiagnostics($allMid),
        );
        $this->assertSame(
            "after-first\n",
            (string) file_get_contents($this->targetOutsidePath),
            $this->collectDiagnostics($allMid),
        );

        $this->assertArrayHasKey('human_input.requested', $byTypeMid, $this->collectDiagnostics($allMid));
        $this->assertArrayNotHasKey('tool_question.requested', $byTypeMid, $this->collectDiagnostics($allMid));

        $secondHitls = array_values(array_filter(
            $byTypeMid['human_input.requested'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === 'call_sg_edit_2',
        ));
        $this->assertNotEmpty(
            $secondHitls,
            'Second outside-CWD edit must request human input again. '.$this->collectDiagnostics($allMid),
        );
        $hitl2 = $secondHitls[0];
        $q2 = (string) ($hitl2['payload']['question_id'] ?? '');
        $tc2 = (string) ($hitl2['payload']['tool_call_id'] ?? '');
        $this->assertNotSame('', $q2, $this->collectDiagnostics($allMid));
        $this->assertNotSame($q1, $q2, 'Second edit must use a distinct question_id. '.$this->collectDiagnostics($allMid));
        $this->assertSame('call_sg_edit_2', $tc2, $this->collectDiagnostics($allMid));
        $this->assertSame('edit', $hitl2['payload']['tool_name'] ?? null, $this->collectDiagnostics($allMid));
        $this->assertSame(
            "after-first\n",
            (string) file_get_contents($this->targetOutsidePath),
            'Second edit must not execute before the second approval. '.$this->collectDiagnostics($allMid),
        );

        $this->writeCommand([
            'v' => 1,
            'id' => 'cmd_answer2_'.uniqid(),
            'type' => 'answer_human',
            'runId' => $this->runId,
            'payload' => [
                'question_id' => $q2,
                'answer' => '❌ Deny',
            ],
        ]);

        $post = $this->collectEventsUntil('run.completed', 25.0);
        $all = array_merge($allMid, $post);
        $byType = $this->indexByType($all);

        $this->assertArrayNotHasKey('tool_question.requested', $byType, $this->collectDiagnostics($all));
        $this->assertArrayHasKey('run.completed', $byType, $this->collectDiagnostics($all));
        $this->assertSame(
            "after-first\n",
            (string) file_get_contents($this->targetOutsidePath),
            'Denied second edit must leave first-edit content only. '.$this->collectDiagnostics($all),
        );

        // Denied call is terminal with a SafeGuard denial payload; is_error may be false
        // on the runtime completed event when the denial is encoded as tool result text.
        // Product proof is no second filesystem side-effect + no tool_question path.
        $secondCompleted = array_values(array_filter(
            $byType['tool_execution.completed'] ?? [],
            static fn (array $e): bool => ($e['payload']['tool_call_id'] ?? null) === 'call_sg_edit_2',
        ));
        $this->assertNotEmpty($secondCompleted, $this->collectDiagnostics($all));
        $secondResult = (string) ($secondCompleted[0]['payload']['result'] ?? '');
        $this->assertStringContainsString(
            'safeguard_denied',
            $secondResult,
            'Second edit must end as a SafeGuard denial, not a successful apply. '.$this->collectDiagnostics($all),
        );
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

        if ('edit_twice' === $this->scenario) {
            $editDeltas = static function (string $callId, string $patch) use ($path): array {
                return [
                    ['type' => 'tool_call_start', 'id' => $callId, 'name' => 'edit'],
                    ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'edit', 'partial_json' => '{"path":"'],
                    ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'edit', 'partial_json' => $path],
                    ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'edit', 'partial_json' => '","patch":"'],
                    // Escape newlines for partial JSON stream; complete args carry the real patch.
                    ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'edit', 'partial_json' => str_replace("\n", '\\n', $patch)],
                    ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'edit', 'partial_json' => '"}'],
                    ['type' => 'tool_call_complete', 'tool_calls' => [
                        ['id' => $callId, 'name' => 'edit', 'arguments' => ['path' => $path, 'patch' => $patch]],
                    ]],
                ];
            };

            return [
                [
                    'model' => 'llama_cpp/test',
                    'provider_id' => 'llama_cpp',
                    'reasoning' => 'off',
                    'deltas' => $editDeltas('call_sg_edit_1', "@@\n-before\n+after-first"),
                    'stop_reason' => 'tool_call',
                ],
                [
                    'model' => 'llama_cpp/test',
                    'provider_id' => 'llama_cpp',
                    'reasoning' => 'off',
                    'deltas' => $editDeltas('call_sg_edit_2', "@@\n-after-first\n+after-second"),
                    'stop_reason' => 'tool_call',
                ],
                [
                    'model' => 'llama_cpp/test',
                    'provider_id' => 'llama_cpp',
                    'reasoning' => 'off',
                    'deltas' => [
                        ['type' => 'text', 'content' => 'done'],
                    ],
                    'stop_reason' => 'stop',
                ],
            ];
        }

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
