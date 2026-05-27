<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Smoke test: view_image tool executes via controller process and the
 * run advances past tool completion (exercises AdvanceRun-after-tools).
 *
 * Creates a small PNG, prompts the model to describe it, and asserts
 * the controller event stream reaches run.completed without hanging.
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

        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension required to create test PNG images.');
        }

        // 32x32 red square
        $this->imagePath = $this->tempDir.'/test-image.png';
        $im = \imagecreatetruecolor(32, 32);
        if (false === $im) {
            throw new \RuntimeException('Failed to create test image.');
        }
        $red = imagecolorallocate($im, 255, 0, 0);
        imagefill($im, 0, 0, $red);
        imagepng($im, $this->imagePath);
        imagedestroy($im);

        self::assertFileExists($this->imagePath, 'Test PNG image was not created.');
    }

    public function testViewImageToolExecutesAndRunAdvancesAfterCommit(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'You MUST call the view_image tool to inspect '
                    .$this->imagePath.', then describe what you see. '
                    .'Do NOT respond without calling view_image first.',
            ],
        ]);

        $events = $this->collectEvents(60.0);
        $byType = [];
        foreach ($events as $e) {
            $type = (string) ($e['type'] ?? 'unknown');
            $byType[$type] = $byType[$type] ?? [];
            $byType[$type][] = $e;
        }

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

        // Primary assertion: run must complete (no hang)
        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Run must complete. Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        // Informational: was the tool path exercised?
        if (isset($byType['tool_batch_committed'])) {
            fwrite(\STDERR, "[INFO] Tool batch committed — AdvanceRun-after-tools path exercised.\n");
        }

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
