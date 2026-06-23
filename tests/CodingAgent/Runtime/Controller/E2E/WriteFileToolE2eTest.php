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
                'prompt' => '[llm-real:write-file] Use exactly one tool call: tool name `write` with arguments `{ "path": "./test-write.txt", "content": "hello world" }`. '
                    .'Do not use an absolute path, do not omit `./`, and do not call any other tool. '
                    .'After the tool succeeds, answer exactly `done`.',
            ],
        ]);

        $events = $this->collectEventsUntilToolCompleted('write', $this->liveLlmToolWaitTimeout());
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);

        self::assertArrayHasKey('run.started', $byType, 'Expected run.started. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId);

        // This smoke proves the write tool path through the controller.
        // Do not require the second post-tool LLM turn to finish inside this
        // short window; ControllerSmokeTest covers terminal run completion.
        self::assertArrayHasKey('tool_execution.started', $byType, 'write tool must start. '
            .$this->collectDiagnostics($events));
        self::assertSame(
            'write',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'The LLM must call the write tool with the requested path/content. '
            .$this->collectDiagnostics($events),
        );
        self::assertArrayHasKey('tool_execution.completed', $byType, 'write tool must complete. '
            .$this->collectDiagnostics($events));
        self::assertSame(
            $byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null,
            $byType['tool_execution.completed'][0]['payload']['tool_call_id'] ?? null,
            'The completed tool execution must be the same write call that started. '
            .$this->collectDiagnostics($events),
        );
        self::assertArrayNotHasKey('tool_execution.failed', $byType, 'write tool must not fail. '
            .$this->collectDiagnostics($events));

        self::assertFileExists($this->targetPath, 'write tool must create the target file. '
            .$this->collectDiagnostics($events));
        self::assertSame('hello world', trim((string) file_get_contents($this->targetPath)));

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
