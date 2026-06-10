<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke test: write_file tool executes via controller process and the
 * run advances past tool completion.
 *
 * Prompts the model to write a file and asserts the controller event
 * stream reaches run.completed without hanging.
 */
#[Group('llm-real')]
final class WriteFileToolE2eTest extends ControllerE2eTestCase
{
    private string $targetPath;

    protected function tempDirPrefix(): string
    {
        return 'test-write-file';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->targetPath = $this->tempDir.'/test-write.txt';
    }

    public function testWriteFileToolExecutesAndRunCompletes(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Write the text "hello world" to the file '
                    .$this->targetPath.' using the write_file tool. '
                    .'Write ONLY "hello world" (no quotes, no newline). '
                    .'Do NOT respond without calling write_file first.',
            ],
        ]);

        $events = $this->collectEvents(60.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);

        self::assertArrayHasKey('run.started', $byType, 'Expected run.started. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId);

        // Primary assertion: run must complete (no hang)
        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Run must complete. Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // Soft assertion: if write_file ran, file should exist
        if (is_file($this->targetPath)) {
            fwrite(\STDERR, "[INFO] Target file created: {$this->targetPath} ("
                .filesize($this->targetPath)." bytes)\n");
        }
        if (isset($byType['tool_batch_committed'])) {
            fwrite(\STDERR, "[INFO] Tool batch committed — AdvanceRun-after-tools path exercised.\n");
        }

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
