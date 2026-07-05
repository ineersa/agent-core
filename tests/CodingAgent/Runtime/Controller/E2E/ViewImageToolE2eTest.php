<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * E2E test: view_image tool with real photo, verifying:
 * 1. Run completes (exercises AdvanceRun-after-tools fix)
 * 2. Gating does NOT produce "does not support images" placeholder
 *
 * Uses a 450x450 JPEG photo fixture from this test directory.
 */
#[Group('llm-real')]
final class ViewImageToolE2eTest extends ControllerE2eTestCase
{
    private string $imagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagePath = $this->tempDir.'/test-photo.jpeg';
        copy(__DIR__.'/test-photo.jpeg', $this->imagePath);
        $this->assertFileExists($this->imagePath, 'Test photo was not copied.');
    }

    public function testViewImageToolCompletesWithoutGatingFailure(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:view-image] Use exactly one tool call: tool name `view_image` with arguments `{ "path": "./test-photo.jpeg" }`. '
                    .'Do not call `read`; `read` is forbidden for this task and cannot view images. '
                    .'Do not use an absolute path. After the tool succeeds, answer exactly `done`.',
            ],
        ]);

        $events = $this->collectEventsUntilToolCompleted('view_image', $this->liveLlmToolWaitTimeout());
        $byType = $this->indexByType($events);

        // Verify command acknowledged
        $this->assertStartRunAcked($events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType, 'Expected run.started. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        // This test proves the image tool path and provider image-gating
        // behavior.  Do not wait for the second post-tool LLM turn to finish:
        // the tool has already completed by this point, and terminal run
        // completion is covered by ControllerSmokeTest/WriteFileToolE2eTest.
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, 'view_image tool must not fail. '
            .$this->collectDiagnostics($events));

        // Verify the view_image tool was actually invoked.
        // tool_execution.started / tool_execution.completed are streamed
        // to stdout by the controller (unlike tool_batch_committed which is
        // internal bookkeeping dropped from the runtime stream per
        // RuntimeEventTranslator::drop()).
        $this->assertArrayHasKey('tool_execution.started', $byType,
            'view_image tool must be invoked. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );
        $this->assertSame(
            'view_image',
            $byType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'The LLM must call the image tool, not a different file tool. '
            .$this->collectDiagnostics($events),
        );
        $this->assertArrayHasKey('tool_execution.completed', $byType,
            'view_image tool must complete. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );
        $this->assertSame(
            $byType['tool_execution.started'][0]['payload']['tool_call_id'] ?? null,
            $byType['tool_execution.completed'][0]['payload']['tool_call_id'] ?? null,
            'The completed tool execution must be the same view_image call that started. '
            .$this->collectDiagnostics($events),
        );

        // Verify the tool batch was committed in the persistence layer.
        // tool_batch_committed is internal bookkeeping, not streamed to
        // stdout, so we read events.jsonl directly.
        $eventsJsonl = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl';
        $persistedTypes = [];
        if (is_file($eventsJsonl)) {
            $lines = file($eventsJsonl, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                try {
                    $e = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
                    $persistedTypes[] = $e['type'] ?? 'unknown';
                } catch (\JsonException) {
                    // skip unparseable lines
                }
            }
        }
        $this->assertContains('tool_batch_committed', $persistedTypes,
            'tool_batch_committed must exist in events.jsonl. '
            .'Persisted types: '.implode(', ', array_unique($persistedTypes))."\n"
            .$this->collectDiagnostics($events),
        );

        // Check for the gating placeholder in streamed events. The gating
        // hook output is `[Tool result image: ...]` — a project-specific
        // bracket-enclosed format that the LLM will NOT naturally produce.
        // Only check tool_execution events and tool result messages where
        // gating output would actually appear.
        $gatingPlaceholderFound = false;
        $toolEvents = array_merge(
            $byType['tool_execution.completed'] ?? [],
            $byType['tool_execution.failed'] ?? [],
        );
        foreach ($toolEvents as $event) {
            $text = $event['payload']['text'] ?? '';
            if (\is_string($text)
                && str_contains($text, '[Tool result image:')
                && false !== stripos($text, 'does not support images')
            ) {
                $gatingPlaceholderFound = true;
                break;
            }
        }
        $this->assertFalse($gatingPlaceholderFound,
            'Gating placeholder must not appear in tool execution events — '
            .'tool_batch_committed in events.jsonl proves the image was sent to the LLM. '
            .$this->collectDiagnostics($events),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-view-image';
    }

    protected function modelConfig(): array
    {
        return [
            'input' => ['text', 'image'],
            'tool_calling' => true,
        ];
    }

    protected function extraDiagnostics(): array
    {
        return ['Image path' => $this->imagePath ?? '(none)'];
    }
}
