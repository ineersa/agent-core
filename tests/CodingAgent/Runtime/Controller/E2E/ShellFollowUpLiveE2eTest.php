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
    /**
     * Isolation test: follow_up on a completed run with NO shell in between.
     * If this fails, the issue is in the follow_up path itself (generic),
     * not specific to the shell-command interaction.
     */
    public function testFollowUpWithoutShell(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // ── Turn 1 ──
        $startCmdId = 'cmd_turn1_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => ['prompt' => '[llm-real:shell-followup-no-shell] Respond with exactly one word: hello.'],
        ]);

        $turn1Events = $this->collectEvents($this->liveLlmRunWaitTimeout());
        $byType = $this->indexByType($turn1Events);
        $this->assertStartRunAcked($turn1Events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType,
            'Turn 1: expected run.started. '.$this->collectDiagnostics($turn1Events));

        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $this->assertTrue(
            $this->hasAssistantResponseEvidence($byType),
            'Turn 1: expected assistant response. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        $this->assertTrue(
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

        $followUpEvents = $this->collectEvents($this->liveLlmRunWaitTimeout());
        $followUpByType = $this->indexByType($followUpEvents);

        $this->assertTrue($this->foundAck($followUpEvents, $followUpCmdId),
            'Follow-up: expected command.ack. '.$this->collectDiagnostics($followUpEvents));

        // The follow-up MUST produce an assistant response.
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($followUpByType),
            'Follow-up without shell: NO assistant response — follow_up broken generically. '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        $this->assertTrue(
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
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // ── Turn 1 ──
        $startCmdId = 'cmd_turn1_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => ['prompt' => '[llm-real:shell-followup-with-shell] Respond with exactly one word: hello.'],
        ]);

        $turn1Events = $this->collectEvents($this->liveLlmRunWaitTimeout());
        $byType = $this->indexByType($turn1Events);
        $this->assertStartRunAcked($turn1Events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType,
            'Turn 1: expected run.started. '.$this->collectDiagnostics($turn1Events));

        $this->runId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $this->assertTrue(
            $this->hasAssistantResponseEvidence($byType),
            'Turn 1: expected assistant response. '
            .'Event types: '.implode(', ', array_keys($byType))."\n"
            .$this->collectDiagnostics($turn1Events),
        );

        $this->assertTrue(
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
            'payload' => [
                'text' => 'ls -1',
                'original_text' => '!ls -1',
                'standalone' => true,
            ],
        ]);

        $shellEvents = $this->collectEventsUntilToolCompleted('bash', $this->liveLlmToolWaitTimeout());
        $shellByType = $this->indexByType($shellEvents);

        $this->assertTrue($this->foundAck($shellEvents, $shellCmdId),
            'Shell: expected command.ack. '.$this->collectDiagnostics($shellEvents));

        $this->assertTrue(
            isset($shellByType['tool_execution.started']),
            'Shell: expected tool_execution.started. '.$this->collectDiagnostics($shellEvents),
        );
        $this->assertTrue(
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

        $followUpEvents = $this->collectEventsUntil('assistant.message_started', $this->liveLlmRunWaitTimeout());
        $followUpByType = $this->indexByType($followUpEvents);
        if (!isset($followUpByType['run.completed']) && !isset($followUpByType['run.failed'])) {
            $followUpEvents = array_merge($followUpEvents, $this->collectEvents($this->liveLlmRunWaitTimeout()));
            $followUpByType = $this->indexByType($followUpEvents);
        }

        $this->assertTrue($this->foundAck($followUpEvents, $followUpCmdId),
            'Follow-up: expected command.ack. '.$this->collectDiagnostics($followUpEvents));

        // Check for command rejection.
        if (isset($followUpByType['command.rejected'])) {
            $rejected = $followUpByType['command.rejected'][0];
            $this->fail(
                'Follow-up was REJECTED: '
                .json_encode($rejected, \JSON_PRETTY_PRINT)."\n"
                .$this->collectDiagnostics($followUpEvents),
            );
        }

        // Check for protocol error.
        if (isset($followUpByType['protocol.error'])) {
            $err = $followUpByType['protocol.error'][0];
            $this->fail(
                'Follow-up produced protocol.error: '
                .json_encode($err, \JSON_PRETTY_PRINT)."\n"
                .$this->collectDiagnostics($followUpEvents),
            );
        }

        // THE KEY ASSERTION
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($followUpByType),
            'Follow-up: NO assistant response — run appears DEAD (issue #183). '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        $this->assertTrue(
            isset($followUpByType['run.completed']) || isset($followUpByType['run.failed']),
            'Follow-up: expected terminal state. '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-shell-followup';
    }

    protected function controllerExtraArgs(): array
    {
        // Do NOT exclude bash — shell commands are the feature under test.
        return [];
    }
}
