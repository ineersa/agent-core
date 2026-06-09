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

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagePath = $this->tempDir.'/test-photo.jpeg';
        copy(__DIR__.'/test-photo.jpeg', $this->imagePath);
        self::assertFileExists($this->imagePath, 'Test photo was not copied.');
    }

    public function testViewImageToolCompletesWithoutGatingFailure(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Describe the image at '.$this->imagePath
                    .'. Call view_image first.',
            ],
        ]);

        $events = $this->collectEvents(60.0);
        $byType = $this->indexByType($events);

        // Verify command acknowledged
        $this->assertStartRunAcked($events, $startCmdId);

        self::assertArrayHasKey('run.started', $byType, 'Expected run.started. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId);

        // Primary: run must complete (no hang)
        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Run must complete. Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // Verify the image-capable tool was actually invoked.
        // llcama_cpp_test/test supports images; the issue is confirming
        // the view_image path executed, not rejecting generic LLM prose.
        self::assertArrayHasKey('tool_batch_committed', $byType,
            'view_image tool batch must be committed. '
            .'If tool_batch_committed is missing, the view_image tool never executed. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // The exact gating placeholder contains both "Actual image omitted"
        // AND "active model does not support images" as a single phrase.
        // Checking both together avoids false positives from the LLM
        // naturally mentioning image support in its own explanatory prose.
        $gatingPlaceholderFound = false;
        foreach ($events as $event) {
            $text = $event['payload']['text'] ?? '';
            if (\is_string($text)
                && str_contains($text, 'Actual image omitted')
                && stripos($text, 'does not support images') !== false
            ) {
                $gatingPlaceholderFound = true;
                break;
            }
        }
        self::assertFalse($gatingPlaceholderFound,
            'Gating placeholder must not appear — tool_batch_committed proves the image was sent to the LLM. '
            .$this->collectDiagnostics($events),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
