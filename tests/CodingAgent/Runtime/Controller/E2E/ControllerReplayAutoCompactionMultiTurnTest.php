<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller replay E2E — reproduces the post-auto-compaction
 * continuation bug discovered during COMP-06 live smoke.
 *
 * Unlike the rejected one-turn live test, this test builds a proper
 * multi-turn session (2 assistant turns) via replay-backed LLM fixtures,
 * then proves the structural invariant: after auto context_compaction
 * completes, the system MUST NOT emit turn_advanced / leaf_set /
 * llm_step_completed / llm_step_failed until an explicit user command.
 *
 * Replay fixture design:
 *   Turn 1 (fixture 0): input_tokens=100  — below compact_after_tokens, no auto yet
 *   Turn 2 (fixture 1): input_tokens=5000 — above compact_after_tokens, triggers auto
 *   Compaction (fixture 2): summary text      — the compaction LLM call
 *   Ghost (fixture 3): explicit text           — if the bug causes ghost continuation
 *
 * The test thesis: after the auto compaction lifecycle terminates, no ghost
 * LLM continuation occurs.  The current bug (HEAD da028146d) emits
 * turn_advanced → leaf_set → llm_step_* after context_compacted, which
 * this test catches.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayAutoCompactionMultiTurnTest extends ControllerReplayE2eTestCase
{
    // ─────────────────────────────────────────────────────────────────
    //  Single test method
    // ─────────────────────────────────────────────────────────────────

    public function testMultiTurnAutoCompactionDoesNotCauseGhostLlmStep(): void
    {
        $this->spawnController();

        // ── Wait for runtime.ready ──
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // ═════════════════════════════════════════════════════════════
        //  Turn 1: start_run — collect until run.completed
        // ═════════════════════════════════════════════════════════════

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Write one sentence about automated testing in software development.',
            ],
        ]);

        $turn1Events = $this->collectEvents(12.0);
        $byType = $this->indexByType($turn1Events);

        $this->assertStartRunAcked($turn1Events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType, 'Expected run.started for turn 1');

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        $this->assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Turn 1 must reach terminal state (run.completed or run.failed).'
            ."\n".$this->collectDiagnostics($turn1Events),
        );

        if (isset($byType['run.failed'])) {
            $err = $byType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 1 failed unexpectedly: {$err}\n"
                .$this->collectDiagnostics($turn1Events));
        }

        // ═════════════════════════════════════════════════════════════
        //  Turn 2: follow_up — collect past run.completed until
        //          compaction event appears (auto after turn commit)
        // ═════════════════════════════════════════════════════════════

        $followUpCmdId = 'cmd_followup_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => 'Write one sentence comparing automated testing to manual testing.',
            ],
        ]);

        // Collect runtime events beyond the terminal run state so we
        // catch the after-turn auto-compaction events (compaction.started,
        // compaction.completed / compaction.failed).
        $allTurn2Events = $this->collectTurnEventsWithAsyncCompaction('run.completed', 12.0);
        $turn2ByType = $this->indexByType($allTurn2Events);

        $this->assertTrue(
            $this->foundAck($allTurn2Events, $followUpCmdId),
            'Expected command.ack for follow_up (cmdId='.$followUpCmdId.'). '
            .$this->collectDiagnostics($allTurn2Events),
        );

        // ═════════════════════════════════════════════════════════════
        //  Structural proof from canonical events.jsonl
        // ═════════════════════════════════════════════════════════════

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->sessionId;
        $eventsPath = $sessionDir.'/events.jsonl';

        $this->assertFileExists($eventsPath, 'Session events.jsonl must exist');

        $coreEvents = $this->loadCoreEvents($eventsPath);
        $this->assertNotEmpty($coreEvents, 'events.jsonl must have events');

        $timeline = $this->buildTimeline($coreEvents);

        // ── Assert: at least 2 llm_step_completed BEFORE auto terminal ──
        // This proves the session is genuinely multi-turn (not degraded to
        // one-turn).  Without this gate a one-turn reproducer could sneak
        // through and give a false sense of coverage.

        $autoTerminalSeq = $this->findAutoCompactionTerminalSeq($coreEvents);

        $this->assertNotNull(
            $autoTerminalSeq,
            'events.jsonl must contain an auto compaction terminal event '
            ."(context_compacted or context_compaction_failed with trigger=auto).\n"
            ."Did auto compaction fire? Check provider usage vs compact_after_tokens.\n"
            ."Timeline:\n".$timeline,
        );

        $llmStepsBeforeAuto = 0;
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq >= $autoTerminalSeq) {
                break;
            }
            $type = $evt['type'] ?? '';
            if ('llm_step_completed' === $type) {
                ++$llmStepsBeforeAuto;
                $usage = $evt['payload']['usage'] ?? [];
                $inputTokens = $usage['input_tokens'] ?? '?';
                $timeline .= "\n  → llm_step_completed at seq={$seq} with input_tokens={$inputTokens}";
            }
        }

        $this->assertGreaterThanOrEqual(
            2,
            $llmStepsBeforeAuto,
            'Reproducer requires at least 2 llm_step_completed events before the auto '
            ."compaction terminal (found {$llmStepsBeforeAuto}).  The session degenerated "
            ."to less than 2 assistant turns — this is not a valid multi-turn reproducer.\n"
            ."Timeline:\n".$timeline,
        );

        // ── Confirm the auto terminal is a success, not a failure ──
        // Structural failures (too_few_messages, no_safe_boundary) are
        // false paths for this reproducer — we need a real compaction.
        $autoTerminalEvt = null;
        foreach ($coreEvents as $evt) {
            if ((int) ($evt['seq'] ?? 0) === $autoTerminalSeq) {
                $autoTerminalEvt = $evt;
                break;
            }
        }

        $this->assertNotNull($autoTerminalEvt, 'Could not find auto terminal event');

        $termType = $autoTerminalEvt['type'] ?? '';
        if ('context_compaction_failed' === $termType) {
            $payload = $autoTerminalEvt['payload'] ?? [];
            $reason = $payload['reason'] ?? 'unknown';
            $this->fail(
                "Auto compaction failed (reason={$reason}) instead of succeeding. "
                .'This is not a valid reproducer for the ghost-continuation bug — '
                ."the compaction must succeed to exercise the post-compaction path.\n"
                ."Timeline:\n".$timeline,
            );
        }

        $this->assertSame(
            'context_compacted',
            $termType,
            "Expected context_compacted with trigger=auto. Got {$termType}.\n"
            ."Timeline:\n".$timeline,
        );

        $cp = $autoTerminalEvt['payload'] ?? [];
        $this->assertSame('auto', $cp['trigger'] ?? '', 'context_compacted trigger must be auto');
        $this->assertGreaterThan(
            0,
            $cp['messages_compacted'] ?? 0,
            'context_compacted must report messages_compacted > 0',
        );

        // ═════════════════════════════════════════════════════════════
        //  THE RED ASSERTION
        //
        //  After the auto compaction lifecycle terminal, there must be
        //  NO turn_advanced, leaf_set, llm_step_completed, or
        //  llm_step_failed events.  These indicate the system treated
        //  auto-compaction completion as a reason to continue the
        //  conversation — the bug we want to reproduce.
        // ═════════════════════════════════════════════════════════════

        $postCompactionForbidden = [];
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq <= $autoTerminalSeq) {
                continue;
            }
            $type = $evt['type'] ?? '';
            if (\in_array($type, ['turn_advanced', 'llm_step_completed', 'llm_step_failed', 'leaf_set'], true)) {
                $err = $evt['payload']['error'] ?? [];
                $postCompactionForbidden[] = \sprintf(
                    '  seq=%d type=%s trigger=%s error_type=%s message=%s',
                    $seq,
                    $type,
                    $evt['payload']['trigger'] ?? '',
                    $err['type'] ?? '',
                    $err['message'] ?? '',
                );
            }
        }

        $this->assertEmpty(
            $postCompactionForbidden,
            "Auto compaction must not cause extra LLM turns.\n"
            ."Found post-compaction forbidden events (after auto terminal seq {$autoTerminalSeq}):\n"
            .implode("\n", $postCompactionForbidden)."\n"
            ."\nFull timeline:\n".$timeline."\n"
            ."\nRuntime events:\n".$this->collectDiagnostics($allTurn2Events),
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-auto-compact-multi';
    }

    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => false,
        ];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<YAML
compaction:
    auto_enabled: true
    compact_after_tokens: 1000
    keep_recent_tokens: 50
YAML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [
            // ── Fixture 0: Start-run turn 1 — below compact_after_tokens ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — turn 1 (below threshold)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E for auto-compaction: '
                    .'turn 1 with low input_tokens so auto-compaction does NOT fire yet',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Automated testing in software development is a critical quality assurance practice that involves using specialized tools and frameworks to execute pre-defined test cases automatically, without requiring manual human intervention. This approach enables development teams to run comprehensive test suites on every code change, providing rapid feedback on regressions and ensuring that new modifications do not break existing functionality. Continuous integration pipelines commonly integrate automated unit tests, integration tests, and end-to-end tests to validate the system at multiple levels of abstraction simultaneously, significantly reducing the time between writing code and discovering defects compared to traditional manual testing workflows.'],
                ],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 80,
                    'total_tokens' => 180,
                ],
                'stop_reason' => 'stop',
                'replay_match' => [
                    'last_user_contains' => 'automated testing in software development',
                ],
            ],

            // ── Fixture 1: Follow-up turn 2 — above compact_after_tokens ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — turn 2 (above threshold)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E for auto-compaction: '
                    .'turn 2 with high input_tokens=5000 so auto-compaction fires by after-turn hook',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Manual testing involves human testers executing test cases step by step, observing the software behavior directly, and documenting their findings. While this approach allows for intuitive exploration and ad-hoc testing that can uncover unexpected edge cases, it is inherently slow, inconsistent across different testers, and cannot scale to support the rapid iteration cycles demanded by modern continuous delivery pipelines. Automated testing complements manual testing by handling repetitive regression checks and data-driven validations that would be impractical for humans to perform repeatedly, allowing manual testers to focus their expertise on exploratory testing, usability evaluation, and complex scenarios that require human judgment and contextual understanding.'],
                ],
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 80,
                    'total_tokens' => 5080,
                ],
                'stop_reason' => 'stop',
                'replay_match' => [
                    'last_user_contains' => 'comparing automated testing to manual testing',
                ],
            ],

            // ── Fixture 2: Auto-compaction summary LLM call ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — compaction summary',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E: the LLM call performed by '
                    .'ExecuteCompactionStepWorker to summarise compacted messages',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: The conversation discussed automated and manual testing approaches.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 15,
                    'total_tokens' => 615,
                ],
                'stop_reason' => 'stop',
                'replay_match' => [
                    'compaction_prompt' => true,
                ],
            ],

            // ── Fixture 3: Ghost continuation (BUG — must not be invoked) ──
            //
            // Turn fixtures use replay_match; this entry has no matcher and is
            // only consumed if an extra LLM call slips through FIFO fallback.
            // The test thesis is enforced by event assertions (no ghost
            // turn_advanced / llm_step_* after compaction), not by this file.
            [
                '$schema' => 'Synthetic multi-turn controller replay — ghost turn (BUG proof)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E: if auto compaction causes a '
                    .'ghost LLM continuation, this fixture answers it so the test can '
                    .'distinguish the ghost event from fixture exhaustion',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'BUG: Ghost LLM response — system continued conversation after auto compaction without user input. This fixture should never be consumed in a passing test.'],
                ],
                'usage' => [
                    'input_tokens' => 700,
                    'output_tokens' => 30,
                    'total_tokens' => 730,
                ],
                'stop_reason' => 'stop',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Local helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Collect runtime events, continuing past run.completed until
     * a compaction lifecycle terminal appears or the timeout expires.
     *
     * This is deliberately NOT stop-at-run.completed: auto compaction
     * fires in the after-turn hook, and its runtime events
     * (compaction.completed / compaction.failed) arrive AFTER the
     * run.completed event.
     *
     * @return list<array<string, mixed>>
     */

    /**
     * Load core events from the canonical events.jsonl.
     *
     * @return list<array<string, mixed>>
     */
    private function loadCoreEvents(string $eventsPath): array
    {
        $core = [];
        foreach (file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $evt = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            if (\is_array($evt)) {
                $core[] = $evt;
            }
        }

        return $core;
    }

    /**
     * Find the seq of the last auto compaction terminal event
     * (context_compacted or context_compaction_failed with trigger=auto).
     *
     * @param list<array<string, mixed>> $coreEvents
     */
    private function findAutoCompactionTerminalSeq(array $coreEvents): ?int
    {
        $terminalTypes = ['context_compacted', 'context_compaction_failed'];
        $found = null;

        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '';
            if (!\in_array($type, $terminalTypes, true)) {
                continue;
            }
            $payload = $evt['payload'] ?? [];
            $trigger = $payload['trigger'] ?? '';
            if ('auto' === $trigger) {
                $found = (int) ($evt['seq'] ?? 0);
            }
        }

        return $found;
    }

    /**
     * Build a compact timeline from core events for diagnostics.
     *
     * @param list<array<string, mixed>> $coreEvents
     */
    private function buildTimeline(array $coreEvents): string
    {
        $lines = [];
        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '?';
            $seq = $evt['seq'] ?? '?';
            $trigger = $evt['payload']['trigger'] ?? '';
            $error = $evt['payload']['error'] ?? [];
            $usage = $evt['payload']['usage'] ?? [];
            $before = $evt['payload']['estimated_tokens_before'] ?? '';
            $after = $evt['payload']['estimated_tokens_after'] ?? '';
            $messagesCompacted = $evt['payload']['messages_compacted'] ?? '';

            $extra = '';
            if ('auto' === $trigger) {
                $extra .= ' trigger=auto';
            }
            if ([] !== $error) {
                $extra .= \sprintf(' error=%s/%s', $error['type'] ?? '?', $error['message'] ?? '?');
            }
            if ([] !== $usage) {
                $extra .= \sprintf(' input_tokens=%s', $usage['input_tokens'] ?? '?');
            }
            if ('' !== (string) $before || '' !== (string) $after) {
                $extra .= \sprintf(' tokens=%s→%s', $before, $after);
            }
            if ('' !== (string) $messagesCompacted) {
                $extra .= \sprintf(' compacted=%s', $messagesCompacted);
            }

            $lines[] = \sprintf('  [%s] %s%s', $seq, $type, $extra);
        }

        return implode("\n", $lines);
    }
}
