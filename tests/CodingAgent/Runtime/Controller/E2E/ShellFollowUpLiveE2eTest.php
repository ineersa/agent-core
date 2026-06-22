<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Live LLM controller E2E tests for the follow-up-after-shell hang (issue #183).
 *
 * Exercises the real controller subprocess with live llama.cpp to catch
 * problems that replay-based tests miss.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class ShellFollowUpLiveE2eTest extends ControllerE2eTestCase
{
    protected function tempDirPrefix(): string
    {
        return 'test-shell-followup';
    }

    protected function controllerExtraArgs(): array
    {
        // Do NOT exclude bash — shell commands are the feature under test.
        return [];
    }

    /**
     * Isolation test: follow_up on a completed run with NO shell in between.
     * If this fails, the issue is in the follow_up path itself (generic),
     * not specific to the shell-command interaction.
     */
    public function testFollowUpWithoutShell(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        // ── Turn 1 ──
        $startCmdId = 'cmd_turn1_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => ['prompt' => 'Respond with exactly one word: hello.'],
        ]);

        $turn1Events = $this->collectEvents(12.0);
        $byType = $this->indexByType($turn1Events);
        $this->assertStartRunAcked($turn1Events, $startCmdId);

        self::assertArrayHasKey('run.started', $byType,
            'Turn 1: expected run.started. '.$this->collectDiagnostics($turn1Events));

        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId);

        self::assertTrue(
            isset($byType['assistant.text_started']) || isset($byType['assistant.message_completed']),
            'Turn 1: expected assistant response. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Turn 1: expected run.completed/run.failed. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        // ── Follow-up (no shell in between — isolation control) ──
        $followUpCmdId = 'cmd_followup_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => 'Say hello again.'],
        ]);

        $followUpEvents = $this->collectEvents(8.0);
        $followUpByType = $this->indexByType($followUpEvents);

        self::assertTrue($this->foundAck($followUpEvents, $followUpCmdId),
            'Follow-up: expected command.ack. '.$this->collectDiagnostics($followUpEvents));

        // The follow-up MUST produce an assistant response.
        $hasAssistant = isset($followUpByType['assistant.text_started'])
            || isset($followUpByType['assistant.message_completed'])
            || isset($followUpByType['assistant.text_delta'])
            || isset($followUpByType['assistant.thinking_started']);
        self::assertTrue(
            $hasAssistant,
            'Follow-up without shell: NO assistant response — follow_up broken generically. '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        self::assertTrue(
            isset($followUpByType['run.completed']) || isset($followUpByType['run.failed']),
            'Follow-up without shell: expected terminal state. '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );
    }

    /**
     * Full scenario:
     *   1. Start run: "Respond with exactly one word: hello."
     *   2. Shell command (!ls -1) on the completed run.
     *   3. Follow-up message ("Say hello again.") — the original hang.
     */
    public function testShellThenFollowUpOnCompletedRun(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', 5.0);

        // ── Turn 1 ──
        $startCmdId = 'cmd_turn1_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => ['prompt' => 'Respond with exactly one word: hello.'],
        ]);

        $turn1Events = $this->collectEvents(12.0);
        $byType = $this->indexByType($turn1Events);
        $this->assertStartRunAcked($turn1Events, $startCmdId);

        self::assertArrayHasKey('run.started', $byType,
            'Turn 1: expected run.started. '.$this->collectDiagnostics($turn1Events));

        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId'] ?? '');
        self::assertNotEmpty($this->runId);

        self::assertTrue(
            isset($byType['assistant.text_started']) || isset($byType['assistant.message_completed']),
            'Turn 1: expected assistant response. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        self::assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Turn 1: expected run.completed/run.failed. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        // ── Shell command ──
        $shellCmdId = 'cmd_shell_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $shellCmdId,
            'type' => 'shell_command',
            'runId' => $this->runId,
            'payload' => ['text' => 'ls -1'],
        ]);

        // Manually collect events with a tighter loop to get ALL events.
        $shellEvents = $this->collectRaw(5.0);
        $shellByType = $this->indexByType($shellEvents);

        self::assertTrue($this->foundAck($shellEvents, $shellCmdId),
            'Shell: expected command.ack. '.$this->collectDiagnostics($shellEvents));

        self::assertTrue(
            isset($shellByType['tool_execution.started']),
            'Shell: expected tool_execution.started. '.$this->collectDiagnostics($shellEvents),
        );
        self::assertTrue(
            isset($shellByType['tool_execution.completed']),
            'Shell: expected tool_execution.completed. '.$this->collectDiagnostics($shellEvents),
        );

        // ── Follow-up (THE CRITICAL PATH) ──
        $followUpCmdId = 'cmd_followup_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => 'Say hello again.'],
        ]);

        // Collect ALL events within the timeout — do NOT stop early on any type.
        $followUpEvents = $this->collectRaw(8.0);
        $followUpByType = $this->indexByType($followUpEvents);

        self::assertTrue($this->foundAck($followUpEvents, $followUpCmdId),
            'Follow-up: expected command.ack. '.$this->collectDiagnostics($followUpEvents));

        // Check for command rejection.
        if (isset($followUpByType['command.rejected'])) {
            $rejected = $followUpByType['command.rejected'][0];
            self::fail(
                'Follow-up was REJECTED: '
                .json_encode($rejected, \JSON_PRETTY_PRINT)."\n"
                .$this->collectDiagnostics($followUpEvents),
            );
        }

        // Check for protocol error.
        if (isset($followUpByType['protocol.error'])) {
            $err = $followUpByType['protocol.error'][0];
            self::fail(
                'Follow-up produced protocol.error: '
                .json_encode($err, \JSON_PRETTY_PRINT)."\n"
                .$this->collectDiagnostics($followUpEvents),
            );
        }

        // THE KEY ASSERTION
        $hasAssistant = isset($followUpByType['assistant.text_started'])
            || isset($followUpByType['assistant.message_completed'])
            || isset($followUpByType['assistant.text_delta'])
            || isset($followUpByType['assistant.thinking_started']);
        self::assertTrue(
            $hasAssistant,
            'Follow-up: NO assistant response — run appears DEAD (issue #183). '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        self::assertTrue(
            isset($followUpByType['run.completed']) || isset($followUpByType['run.failed']),
            'Follow-up: expected terminal state. '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );
    }

    /**
     * Collect all events within the timeout WITHOUT stopping early on any
     * event type.  Unlike collectEvents()/collectEventsUntil(), this does
     * NOT stop at run.completed/run.failed/run.cancelled — it drains the
     * full timeout period so we can see ALL events for diagnostics.
     *
     * @return list<array<string, mixed>>
     */
    private function collectRaw(float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        // Drain remaining events.
        foreach ($this->readEvents() as $event) {
            $events[] = $event;
        }

        return $events;
    }
}
