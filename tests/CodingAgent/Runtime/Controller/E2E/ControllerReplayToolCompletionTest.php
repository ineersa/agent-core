<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-replay proof that an ordinary (non-cancelled) tool
 * call completes through the controller JSONL + Messenger pipeline and persists
 * canonical session evidence.
 *
 * The former started-bash-then-cancel controller journey was a scheduling race:
 * it could not deterministically establish a process-interaction window under
 * normal gate contention. Its contracts are covered at their stable seams:
 * active-tool cancellation and terminalization by ApplyCommandHandlerTest and
 * ToolCallResultHandlerTest; cancelled-run follow-up dispatch by
 * ApplyCommandHandlerTest and AdvanceRunHandlerTest; and replay-controller
 * follow-up assistant output by ControllerReplayAutoCompactionLifecycleTest.
 * SafeGuardApproval covers outside-CWD write + HITL, not this unguarded
 * completion path. This case restores the deleted ControllerReplaySmokeTest
 * contract with early-exit waits and a fail-loud post-tool fixture so exhaustion
 * cannot silently invent a done response.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayToolCompletionTest extends ControllerReplayE2eTestCase
{
    private const string TOOL_CALL_ID = 'call_ctrl_1';
    private const string FILE_CONTENT = 'Hello from controller replay test';

    private string $targetPath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->targetPath = $this->tempDir.'/notes.txt';
        file_put_contents($this->targetPath, self::FILE_CONTENT);
    }

    public function testOrdinaryReadToolCompletesAndPersistsCanonicalEvidence(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call the tool named read exactly once with path ./notes.txt. '
                    .'Do not use an absolute path, and do not call any other tool. '
                    .'After the tool succeeds, answer exactly done.',
            ],
        ]);

        // Multi-hop: controller → LLM consumer (fixture) → tool consumer →
        // tool_execution.completed. Cap under the 10s case ceiling; early-exit
        // on the completed event (do not require run.completed for this contract).
        $events = $this->collectEventsUntil('tool_execution.completed', 8.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must include runId');

        $this->assertArrayHasKey(
            'tool_execution.started',
            $byType,
            'read tool must start. '.$this->collectDiagnostics($events),
        );
        $this->assertSame(
            'read',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            $this->collectDiagnostics($events),
        );
        $this->assertSame(
            self::TOOL_CALL_ID,
            $byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null,
            $this->collectDiagnostics($events),
        );

        $this->assertArrayHasKey(
            'tool_execution.completed',
            $byType,
            'read tool must complete. '.$this->collectDiagnostics($events),
        );
        $this->assertSame(
            self::TOOL_CALL_ID,
            $byType['tool_execution.completed'][0]['payload']['tool_call_id'] ?? null,
            'completed tool_call_id must match started. '.$this->collectDiagnostics($events),
        );
        $this->assertArrayNotHasKey(
            'tool_execution.failed',
            $byType,
            'read tool must not fail. '.$this->collectDiagnostics($events),
        );
        $this->assertArrayNotHasKey('run.failed', $byType, $this->collectDiagnostics($events));

        $this->assertFileExists($this->targetPath);
        $this->assertSame(self::FILE_CONTENT, (string) file_get_contents($this->targetPath));

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);

        $eventsJsonl = $sessionDir.'/events.jsonl';
        $this->assertFileExists($eventsJsonl);
        $jsonlContent = (string) file_get_contents($eventsJsonl);
        $this->assertStringContainsString(
            'tool_execution_end',
            $jsonlContent,
            'events.jsonl must record canonical tool_execution_end. '.$this->collectDiagnostics($events),
        );
        $this->assertStringContainsString(
            self::TOOL_CALL_ID,
            $jsonlContent,
            'events.jsonl must retain the tool_call_id. '.$this->collectDiagnostics($events),
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay-tool-complete';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $fixturePath = __DIR__.'/fixtures/controller-tool-call-replay.json';
        $toolFixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($toolFixture);

        // Absorb the post-tool LLM turn so fail-loud fixture exhaustion cannot
        // invent a successful done response if the pipeline continues after
        // tool_execution.completed.
        $postToolFixture = [
            '$schema' => 'Synthetic controller replay — post-read assistant turn',
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Absorb the post-tool LLM turn after ordinary read completes.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [
                ['type' => 'text', 'content' => 'done'],
            ],
        ];

        return [$toolFixture, $postToolFixture];
    }
}
