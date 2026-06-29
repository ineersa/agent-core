<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller E2E smoke test using LLM replay fixtures.
 *
 * Spawns `bin/console agent --controller` in replay mode, sends a
 * tool-calling prompt, and asserts the full controller pipeline works
 * (runtime.ready → command.ack → run.started → tool execution →
 * events.jsonl & state.json artifacts) WITHOUT any live LLM calls.
 *
 * This is the default controller validation path.  Live LLM smoke
 * remains available opt-in via `castor test:controller`.
 *
 * MAINT-05D: First controller replay E2E proof — exercises the
 * full async runtime pipeline with deterministic fixture data.
 */
#[Group('controller-replay')]
final class ControllerReplaySmokeTest extends ControllerReplayE2eTestCase
{
    private string $targetPath;

    protected function tempDirPrefix(): string
    {
        return 'test-controller-replay';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->targetPath = $this->tempDir.'/notes.txt';
        file_put_contents($this->targetPath, 'Hello from controller replay test');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        // Load the known-good tool-call fixture.
        $fixturePath = __DIR__.'/fixtures/controller-tool-call-replay.json';
        $fixture = json_decode(
            (string) file_get_contents($fixturePath),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        \PHPUnit\Framework\Assert::assertIsArray($fixture);

        return [$fixture];
    }

    /**
     * Prove the controller pipeline works with replay: spawn, prompt a
     * tool call, and assert the tool executes successfully.
     */
    public function testControllerReplayToolExecution(): void
    {
        $this->spawnController();

        // Wait for runtime.ready with a generous timeout — the controller
        // must boot the Symfony kernel, migrate the test DB, and start the
        // event loop.  5s is sufficient for local dev.
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_replay_'.uniqid();
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

        // Collect until the tool completes (or terminal run state).
        // The replay fixture returns a tool_call stop_reason after a
        // single read invocation.  The 12s budget from liveLlmToolWaitTimeout()
        // matches liveControllerReadyTimeout() — under parallel castor check
        // load the controller subprocess + Messenger consumers may be
        // CPU-starved and the old 5s wall-clock budget was too tight.
        $events = $this->collectEventsUntilToolCompleted('read', $this->liveLlmToolWaitTimeout());
        $byType = $this->indexByType($events);

        // ── Mandatory: controller acknowledged the start_run command ──
        $this->assertStartRunAcked($events, $startCmdId);

        // ── Mandatory: run started ──
        self::assertArrayHasKey('run.started', $byType, 'Expected run.started. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        // ── Tool execution proof ──
        self::assertArrayHasKey('tool_execution.started', $byType,
            'read tool must start. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        self::assertSame(
            'read',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'The LLM must call the read tool. '
            .$this->collectDiagnostics($events),
        );

        self::assertArrayHasKey('tool_execution.completed', $byType,
            'read tool must complete. '
            .$this->collectDiagnostics($events),
        );

        self::assertSame(
            $byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null,
            $byType['tool_execution.completed'][0]['payload']['tool_call_id'] ?? null,
            'The completed tool execution must match the started call. '
            .$this->collectDiagnostics($events),
        );

        self::assertArrayNotHasKey('tool_execution.failed', $byType,
            'read tool must not fail. '
            .$this->collectDiagnostics($events),
        );

        // ── File-system side effect: the read tool was executed ──
        self::assertFileExists($this->targetPath,
            'The target file must still exist after the read tool ran.',
        );

        // ── Session artifacts were persisted ──
        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);

        // ── The tool execution was recorded in events.jsonl ──
        $eventsJsonl = $sessionDir.'/events.jsonl';
        self::assertFileExists($eventsJsonl, 'events.jsonl must exist.');
        $jsonlContent = (string) file_get_contents($eventsJsonl);
        self::assertStringContainsString('tool_execution_end', $jsonlContent,
            'events.jsonl must record the completed tool execution.',
        );
    }
}
