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
     * Full scenario:
     *   1. Start run: "Respond with exactly one word: hello."
     *   2. Shell command (!ls -1) on the completed run.
     *   3. Follow-up message ("Say hello again.") — the original hang.
     *
     * Thesis: a follow_up submitted in the shell tool-end→AgentEnd window must
     * eventually produce assistant evidence and its own terminal; a delayed
     * standalone-shell run.completed must not terminate the follow-up phase.
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

        // Return on matching bash tool completion so the real tool-end→AgentEnd
        // race window remains open; do not wait for shell run.completed first.
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

        $phase = $this->collectFollowUpAfterShellPhase($followUpCmdId, $this->liveLlmRunWaitTimeout());
        $followUpEvents = $phase['events'];
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

        // THE KEY ASSERTION
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($followUpByType),
            'Follow-up: NO assistant response — run appears DEAD (issue #183). '
            .'Event types: '.implode(', ', array_keys($followUpByType))."\n"
            .$this->collectDiagnostics($followUpEvents),
        );

        $this->assertTrue(
            $phase['completed'],
            'Follow-up: expected terminal state belonging to the follow-up phase, '
            .'not a delayed standalone-shell terminal. '
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

    protected function controllerSubprocessEnv(): array
    {
        // Two LLM turns (start + follow-up). Keep below the collector budget.
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '15'];
    }

    /**
     * Collect the follow-up phase after a shell tool completion without treating
     * a delayed standalone-shell parent terminal as the follow-up terminal.
     *
     * Returns when:
     * - the follow-up is acked AND assistant evidence has been seen AND a later
     *   parent terminal arrives; or
     * - command.rejected / protocol.error arrives; or
     * - the controller process dies (after a final drain); or
     * - the timeout elapses.
     *
     * @return array{events: list<array<string, mixed>>, completed: bool}
     */
    private function collectFollowUpAfterShellPhase(string $followUpCmdId, float $timeout): array
    {
        $events = [];
        $deadline = microtime(true) + $timeout;
        $this->parentRunIdForCollection = '' !== $this->runId ? $this->runId : null;

        $followUpAcked = false;
        $assistantSeen = false;
        $completed = false;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $this->noteParentRunIdFromEvent($event);

                $type = (string) ($event['type'] ?? '');
                $payload = $event['payload'] ?? [];
                if (!\is_array($payload)) {
                    $payload = [];
                }

                if ('command.ack' === $type && ($payload['commandId'] ?? '') === $followUpCmdId) {
                    $followUpAcked = true;
                }

                if (\in_array($type, [
                    'assistant.message_started',
                    'assistant.text_started',
                    'assistant.text_delta',
                    'assistant.thinking_started',
                    'assistant.message_completed',
                ], true)) {
                    $assistantSeen = true;
                }

                if ('command.rejected' === $type || 'protocol.error' === $type) {
                    return ['events' => $events, 'completed' => false];
                }

                // Ignore parent terminals until this follow-up has both been
                // acked and produced assistant evidence — otherwise a delayed
                // standalone-shell run.completed tears the phase down early.
                if ($followUpAcked && $assistantSeen && $this->isParentRunTerminalEvent($event)) {
                    $completed = true;

                    return ['events' => $events, 'completed' => true];
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                    $this->noteParentRunIdFromEvent($event);

                    $type = (string) ($event['type'] ?? '');
                    if (\in_array($type, [
                        'assistant.message_started',
                        'assistant.text_started',
                        'assistant.text_delta',
                        'assistant.thinking_started',
                        'assistant.message_completed',
                    ], true)) {
                        $assistantSeen = true;
                    }
                    if ('command.ack' === $type
                        && \is_array($event['payload'] ?? null)
                        && (($event['payload']['commandId'] ?? '') === $followUpCmdId)
                    ) {
                        $followUpAcked = true;
                    }
                    if ($followUpAcked && $assistantSeen && $this->isParentRunTerminalEvent($event)) {
                        $completed = true;
                    }
                }
                break;
            }

            usleep(10_000);
        }

        return ['events' => $events, 'completed' => $completed];
    }
}
