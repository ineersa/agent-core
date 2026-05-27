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
        $byType = [];
        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? 'unknown');
            $byType[$type] = $byType[$type] ?? [];
            $byType[$type][] = $e;
        }

        // Verify command acknowledged
        $acks = $byType['command.ack'] ?? [];
        $foundStartAck = false;
        foreach ($acks as $ack) {
            $payload = $ack['payload'] ?? [];
            if (($payload['commandId'] ?? '') === $startCmdId) {
                $foundStartAck = true;
                break;
            }
        }
        self::assertTrue($foundStartAck, 'Expected command.ack for start_run. '
            .$this->collectDiagnostics($events));

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

        // Critical: gating must NOT produce the failure placeholder
        $allText = '';
        foreach ($events as $event) {
            $text = $event['payload']['text'] ?? '';
            if (\is_string($text)) {
                $allText .= $text;
            }
        }
        self::assertStringNotContainsString(
            'does not support images',
            strtolower($allText),
            'Image gating must not report unsupported model. '
            .'Text collected: '.substr($allText, 0, 500)."\n"
            .$this->collectDiagnostics($events),
        );

        if (isset($byType['tool_batch_committed'])) {
            fwrite(\STDERR, "[INFO] view_image tool executed and batch committed.\n");
        }

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
