<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Baseline end-to-end smoke test for the async controller process.
 *
 * Spawns `bin/console agent --controller`, sends a simple prompt,
 * and asserts the full event sequence: runtime.ready →
 * command.ack(start_run) → run.started → assistant response →
 * run.completed.
 */
#[Group('llm-real')]
final class ControllerSmokeTest extends ControllerE2eTestCase
{
    protected function tempDirPrefix(): string
    {
        return 'test-controller';
    }

    public function testControllerSpawnAndCompleteRun(): void
    {
        $this->spawnController();

        $this->waitForEvent('runtime.ready', 5.0);

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Respond with exactly one word: hello.',
            ],
        ]);

        $events = $this->collectEvents(15.0);
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
        self::assertTrue(
            $foundStartAck,
            'Expected command.ack for start_run command. '
            .'Available acks: '.json_encode($acks, \JSON_THROW_ON_ERROR)."\n"
            .$this->collectDiagnostics($events),
        );

        self::assertArrayHasKey(
            'run.started',
            $byType,
            'Expected run.started event.'."\n"
            .$this->collectDiagnostics($events),
        );

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        $hasStreaming = isset($byType['assistant.text_started']);
        $hasMessageCompleted = isset($byType['assistant.message_completed']);
        self::assertTrue(
            $hasStreaming || $hasMessageCompleted,
            'Expected assistant.text_started or assistant.message_completed. '
            .'Available event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Expected run.completed or run.failed. '
            .'Available event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($events),
        );

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, $events);
    }
}
