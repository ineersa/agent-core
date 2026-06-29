<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller E2E test for the /tree rewind-to-turn protocol.
 *
 * Sends a start_run → creates turn 1 → sends rewind_to_turn → asserts
 * a LeafSet event was appended to the canonical stream and a RunLeafChanged
 * RuntimeEvent was emitted.
 *
 * Uses a single LLM replay fixture (simple text response, no tool calls).
 * A multi-turn fixture is needed to test leaf-change (rewind to a non-current
 * turn), but the protocol path (command handler → RunRewindService → event
 * emission) is exercised even with rewind to the current leaf.
 *
 * @see RewindToTurnHandler
 * @see RunRewindService
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayRewindTest extends ControllerReplayE2eTestCase
{
    protected function tempDirPrefix(): string
    {
        return 'test-controller-rewind';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [
            [
                'input' => [
                    'messages' => [
                        ['role' => 'user', 'content' => 'Say exactly "hello" and nothing else.'],
                    ],
                ],
                'deltas' => [
                    ['type' => 'text', 'content' => 'hello'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13],
                'stop_reason' => 'stop',
                'expected_text' => 'hello',
            ],
        ];
    }

    /**
     * Prove that:
     *  - The RewindToTurnHandler dispatches the rewind when a rewind_to_turn
     *    JSONL command is received.
     *  - A LeafSet event is appended to the canonical events.jsonl stream.
     *  - A RunLeafChanged RuntimeEvent is emitted.
     */
    public function testRewindToTurnAppendsLeafSetAndEmitsRunLeafChanged(): void
    {
        $this->spawnController();

        // Wait for runtime.ready
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_rewind_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Say exactly "hello" and nothing else.',
            ],
        ]);

        // Wait for run.started (proves the controller processed start_run).
        $events = $this->collectEventsUntil('run.started', $this->liveLlmToolWaitTimeout());
        $this->assertStartRunAcked($events, $startCmdId);

        $byType = $this->indexByType($events);
        self::assertArrayHasKey('run.started', $byType, 'Expected run.started after start_run. '
            .$this->collectDiagnostics($events));

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId, 'run.started must have a runId');

        // Wait for the first turn's assistant message to complete, proving
        // that the LLM replay fixture was consumed and the turn events were
        // written to the canonical event stream.
        $events = $this->collectEventsUntil('assistant.message_completed', $this->liveLlmRunWaitTimeout());
        $byType = $this->indexByType($events);

        self::assertArrayHasKey('assistant.message_completed', $byType,
            'First turn should produce an assistant.message_completed event. '
            .$this->collectDiagnostics($events));

        // ── REWIND ──────────────────────────────────────────────────────────

        $rewindCmdId = 'cmd_rewind_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $rewindCmdId,
            'type' => 'rewind_to_turn',
            'runId' => $this->runId,
            'payload' => [
                'turn_no' => 1,
            ],
        ]);

        // Collect events after the rewind command and assert RunLeafChanged
        // was emitted within a generous timeout.
        $events = $this->collectEventsUntil(
            'run.leaf_changed',
            $this->liveLlmToolWaitTimeout(),
        );
        $byType = $this->indexByType($events);

        self::assertArrayHasKey('run.leaf_changed', $byType,
            'rewind_to_turn must emit a run.leaf_changed RuntimeEvent. '
            .$this->collectDiagnostics($events),
        );

        $leafChanged = $byType['run.leaf_changed'][0];
        $payload = $leafChanged['payload'] ?? $leafChanged;
        self::assertSame(1, (int) ($payload['turn_no'] ?? 0),
            'RunLeafChanged payload must reference the target turn (1). '
            .$this->collectDiagnostics($events),
        );

        // ── Verify the LeafSet event was appended to events.jsonl ──

        // Re-read the session directory (created under tempDir).
        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $eventsJsonl = $sessionDir.'/events.jsonl';
        self::assertFileExists($eventsJsonl, 'events.jsonl must exist after start_run.');

        $jsonlContent = (string) file_get_contents($eventsJsonl);
        self::assertStringContainsString('"leaf_set"', $jsonlContent,
            'events.jsonl must contain a leaf_set event after rewind. '
            .'Content excerpt: '.substr($jsonlContent, -2000),
        );
    }
}
