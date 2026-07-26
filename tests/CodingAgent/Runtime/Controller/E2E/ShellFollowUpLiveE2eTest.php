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
            'payload' => ['text' => '!ls -1'],
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

        // Phase boundary: under concurrent gate load, the shell command's
        // trailing parent run.completed can lag behind tool_execution.completed.
        // Soft-drain any already-buffered or shortly-arriving shell terminal
        // BEFORE follow_up so it cannot be consumed as the follow-up outcome.
        // Do not hard-require shell run.completed: bash tool completion is the
        // durable shell-phase success signal; standalone AgentEnd can race or
        // be delayed on the consumer stdout pipe without meaning the shell hung.
        $shellEvents = array_merge(
            $shellEvents,
            $this->drainParentRunTerminalQuietly(0.25),
        );

        // ── Follow-up (THE CRITICAL PATH) ──
        $followUpCmdId = 'cmd_followup_'.uniqid();
        $this->writeCommand([
            'v' => 1, 'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => 'Say hello again.'],
        ]);

        // Correlate follow-up terminal to this command: ignore parent terminals
        // until the follow_up command.ack is observed, then require assistant
        // evidence and a post-ack terminal for THIS turn.
        $followUpEvents = $this->collectFollowUpPhaseEvents(
            $followUpCmdId,
            $this->liveLlmRunWaitTimeout(),
        );
        $followUpByType = $this->indexByType($followUpEvents);

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

        // THE KEY ASSERTION — true dead follow-up still fails here even if a
        // stale shell terminal arrived (it is discarded by the collector until ack).
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($followUpByType),
            'Follow-up: NO assistant response — run appears DEAD (issue #183). '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        $this->assertTrue(
            $this->hasParentRunTerminalAfterAck($followUpEvents, $followUpCmdId),
            'Follow-up: expected terminal state after follow_up command.ack. '
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

    /**
     * Soft-drain any parent terminal that is already buffered or arrives within
     * $timeoutSeconds. Returns immediately after the first parent terminal or
     * when the quiet window elapses — never fails on missing shell terminal.
     *
     * @return list<array<string, mixed>>
     */
    private function drainParentRunTerminalQuietly(float $timeoutSeconds): array
    {
        $events = [];
        $deadline = microtime(true) + $timeoutSeconds;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                if ($this->isParentRunTerminalEvent($event)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /**
     * Collect the follow_up phase correlated to $followUpCmdId.
     *
     * Parent terminals observed before the follow_up command.ack are treated as
     * stale shell-phase leftovers and cannot close this phase. After ack, require
     * assistant evidence plus a parent terminal for the follow-up turn.
     *
     * @return list<array<string, mixed>>
     */
    private function collectFollowUpPhaseEvents(string $followUpCmdId, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;
        $acked = false;
        $sawAssistant = false;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                if (!$acked && $this->foundAck([$event], $followUpCmdId)) {
                    $acked = true;
                }

                $type = (string) ($event['type'] ?? '');
                if (\in_array($type, [
                    'assistant.message_started',
                    'assistant.text_started',
                    'assistant.text_delta',
                    'assistant.thinking_started',
                    'assistant.message_completed',
                ], true)) {
                    $sawAssistant = true;
                }

                // Only a post-ack parent terminal can close the follow-up phase.
                if ($acked && $sawAssistant && $this->isParentRunTerminalEvent($event)) {
                    return $events;
                }

                // Hard stop on rejection/protocol errors so diagnostics stay bounded.
                if (\in_array($type, ['command.rejected', 'protocol.error'], true)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                }
                break;
            }

            usleep(10_000);
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function hasParentRunTerminalAfterAck(array $events, string $cmdId): bool
    {
        $acked = false;
        foreach ($events as $event) {
            if (!$acked && $this->foundAck([$event], $cmdId)) {
                $acked = true;
                continue;
            }

            if ($acked && $this->isParentRunTerminalEvent($event)) {
                return true;
            }
        }

        return false;
    }
}
