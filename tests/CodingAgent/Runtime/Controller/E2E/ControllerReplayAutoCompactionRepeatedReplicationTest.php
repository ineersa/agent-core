<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller replay E2E — reproduces the session 9
 * compactionResolved blocker: after first auto-compaction resolves,
 * auto-compaction must re-fire on later turns when a new provider
 * measurement becomes eligible.
 *
 * The bug: AutoCompactionHookSubscriber::$compactionResolved is a
 * run-scoped in-memory flag set by the containsCompactionLifecycle
 * guard.  Once set, it permanently blocks auto-compaction for the
 * lifetime of the run (until process restart).  Since run_started
 * only clears it once per session, later user turns with new
 * provider measurements can never trigger auto-compaction again.
 *
 * This test proves:
 *  1. Turn 1: below compact_after_tokens — no auto-compaction
 *  2. Turn 2: above threshold — first auto-compaction fires and completes
 *  3. Turn 3: above threshold — second auto-compaction fires and completes
 *     (this assertion FAILS on HEAD where compactionResolved blocks it)
 *  4. No ghost turn_advanced / leaf_set / llm_step_* after any after-turn
 *     compaction terminal
 *
 * Replay fixture design:
 *   Fixture 0: turn 1 LLM response, input_tokens=100 (below threshold)
 *   Fixture 1: turn 2 LLM response, input_tokens=5000 (above threshold)
 *   Fixture 2: first compaction summary (consumed after turn 2)
 *   Fixture 3: turn 3 LLM response, input_tokens=5000 (above threshold)
 *   Fixture 4: second compaction summary (consumed after turn 3)
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayAutoCompactionRepeatedReplicationTest extends ControllerReplayE2eTestCase
{
    // ─────────────────────────────────────────────────────────────────
    //  Single test method
    // ─────────────────────────────────────────────────────────────────

    public function testRepeatedAutoCompactionFiresOnMultipleTurns(): void
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

        $turn1Events = $this->collectEvents(25.0);
        $byType = $this->indexByType($turn1Events);

        $this->assertStartRunAcked($turn1Events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType, 'Expected run.started for turn 1');

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        $this->assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Turn 1 must reach terminal state (run.completed or run.failed).',
        );

        if (isset($byType['run.failed'])) {
            $err = $byType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 1 failed unexpectedly: {$err}");
        }

        // ═════════════════════════════════════════════════════════════
        //  Turn 2: follow_up — collect past run.completed until
        //          first compaction lifecycle
        // ═════════════════════════════════════════════════════════════

        $followUpCmdId2 = 'cmd_followup_2_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId2,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => 'Write one sentence comparing automated testing to manual testing.',
            ],
        ]);

        $turn2Events = $this->collectEventsPastRunCompleted(25.0);
        $turn2ByType = $this->indexByType($turn2Events);

        $this->assertTrue(
            $this->foundAck($turn2Events, $followUpCmdId2),
            'Expected command.ack for follow_up (turn 2).',
        );

        $this->assertTrue(
            isset($turn2ByType['compaction.completed']) || isset($turn2ByType['compaction.failed']),
            "Turn 2 must trigger auto-compaction lifecycle.\n"
            .$this->collectDiagnostics($turn2Events),
        );

        // ═════════════════════════════════════════════════════════════
        //  Turn 3: follow_up — collect past run.completed until
        //          second compaction lifecycle
        //
        //  THIS IS THE RED ASSERTION on HEAD:
        //  compactionResolved blocks auto-compaction, so no
        //  compaction.completed appears within the timeout.
        // ═════════════════════════════════════════════════════════════

        $followUpCmdId3 = 'cmd_followup_3_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId3,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => 'Write one sentence about how automated and manual testing complement each other.',
            ],
        ]);

        $turn3Events = $this->collectEventsPastRunCompleted(25.0);
        $turn3ByType = $this->indexByType($turn3Events);

        $this->assertTrue(
            $this->foundAck($turn3Events, $followUpCmdId3),
            'Expected command.ack for follow_up (turn 3).',
        );

        $this->assertTrue(
            isset($turn3ByType['compaction.completed']) || isset($turn3ByType['compaction.failed']),
            "Turn 3 MUST trigger a second auto-compaction lifecycle.\n"
            ."The compactionResolved flag on HEAD permanently blocks this.\n"
            .$this->collectDiagnostics($turn3Events),
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

        // ── Assert: at least 2 auto context_compacted events ──
        $autoCompactedSeqs = $this->findAutoCompactedSeqs($coreEvents);

        $this->assertGreaterThanOrEqual(
            2,
            \count($autoCompactedSeqs),
            "Session must have at least 2 auto compaction terminal events (one per above-threshold turn).\n"
            .'Found: '.\count($autoCompactedSeqs)."\n"
            ."On HEAD, compactionResolved permanently blocks the second auto-compaction.\n"
            ."Timeline:\n".$timeline,
        );

        // ── Assert: no ghost turn_advanced / leaf_set / llm_step_*
        //    after each auto compaction terminal ──
        $this->assertNoGhostLlmAfterCompactionTerminals($coreEvents, $autoCompactedSeqs, $timeline);

        // ── Assert: at least 3 llm_step_completed before the last
        //    compaction terminal (proves genuine multi-turn) ──
        $lastAutoSeq = max($autoCompactedSeqs);
        $llmStepsBeforeLast = 0;
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq >= $lastAutoSeq) {
                break;
            }
            if ('llm_step_completed' === ($evt['type'] ?? '')) {
                ++$llmStepsBeforeLast;
            }
        }

        $this->assertGreaterThanOrEqual(
            3,
            $llmStepsBeforeLast,
            'Session must have at least 3 llm_step_completed events before the last '
            ."compaction terminal to prove genuine multi-turn (found {$llmStepsBeforeLast}).\n"
            ."Timeline:\n".$timeline,
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-auto-compact-repeat';
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
            // ── Fixture 0: Turn 1 — below threshold ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — turn 1 (below threshold)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 9 replication: '
                    .'turn 1 with low input_tokens=100 so auto-compaction does NOT fire yet.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Automated testing in software development is a critical quality assurance practice that involves using specialized tools and frameworks to execute pre-defined test cases automatically, without requiring manual human intervention. This approach enables development teams to run comprehensive test suites on every code change, providing rapid feedback on regressions and ensuring that new modifications do not break existing functionality. Continuous integration pipelines commonly integrate automated unit tests, integration tests, and end-to-end tests to validate the system at multiple levels of abstraction simultaneously.'],
                ],
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 80,
                    'total_tokens' => 180,
                ],
                'stop_reason' => 'stop',
            ],

            // ── Fixture 1: Turn 2 — above threshold, triggers first auto ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — turn 2 (above threshold)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 9 replication: '
                    .'turn 2 with high input_tokens=5000 > compact_after_tokens=1000 '
                    .'so auto-compaction fires via after-turn hook.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Manual testing involves human testers executing test cases step by step, observing the software behavior directly, and documenting their findings. While this approach allows for intuitive exploration and ad-hoc testing that can uncover unexpected edge cases, it is inherently slow, inconsistent across different testers, and cannot scale to support the rapid iteration cycles demanded by modern continuous delivery pipelines. Automated testing complements manual testing by handling repetitive regression checks and data-driven validations that would be impractical for humans to perform repeatedly.'],
                ],
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 80,
                    'total_tokens' => 5080,
                ],
                'stop_reason' => 'stop',
            ],

            // ── Fixture 2: First compaction summary ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — first compaction summary',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 9 replication: '
                    .'first compaction LLM call consumed after turn 2 auto-compaction triggers.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: The conversation covered automated testing benefits.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 12,
                    'total_tokens' => 612,
                ],
                'stop_reason' => 'stop',
            ],

            // ── Fixture 3: Turn 3 — above threshold, triggers second auto ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — turn 3 (above threshold)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 9 replication: '
                    .'turn 3 with high input_tokens=5000 > compact_after_tokens=1000 '
                    .'— the second auto-compaction MUST fire because compactionResolved '
                    .'permanently blocks it on HEAD (the bug).',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Both automated and manual testing strategies complement each other in a mature quality process. Automated tests provide rapid, consistent feedback on regressions and functional correctness across every code change, while manual testing enables creative exploration, usability assessment, and the discovery of edge cases that scripted automated tests might miss. Organizations that invest in both approaches, integrating automated checks into their continuous integration pipeline while reserving manual testing for high-value exploratory and user experience work, achieve better overall software quality than those relying exclusively on either approach alone.'],
                ],
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 80,
                    'total_tokens' => 5080,
                ],
                'stop_reason' => 'stop',
            ],

            // ── Fixture 4: Second compaction summary ──
            [
                '$schema' => 'Synthetic multi-turn controller replay — second compaction summary',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 9 replication: '
                    .'second compaction LLM call consumed after turn 3.  '
                    .'On HEAD this fixture should NEVER be consumed because '
                    .'compactionResolved blocks the second auto-compaction.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: The conversation compared automated and manual testing approaches, concluding they complement each other.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 18,
                    'total_tokens' => 618,
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
     * @return list<array<string, mixed>>
     */
    private function collectEventsPastRunCompleted(float $timeoutSeconds): array
    {
        $events = [];
        $deadline = microtime(true) + $timeoutSeconds;
        $lastEventAt = microtime(true);
        $sawRunTerminal = false;

        while (microtime(true) < $deadline) {
            foreach ($this->readEvents() as $event) {
                $events[] = $event;
                $lastEventAt = microtime(true);
                $type = $event['type'] ?? '';

                if (\in_array($type, ['run.completed', 'run.failed'], true)) {
                    $sawRunTerminal = true;
                }

                if (\in_array($type, ['compaction.completed', 'compaction.failed'], true)) {
                    return $events;
                }
            }

            if (!$this->isRunning()) {
                foreach ($this->readEvents() as $event) {
                    $events[] = $event;
                    $lastEventAt = microtime(true);
                    $type = $event['type'] ?? '';
                    if (\in_array($type, ['run.completed', 'run.failed'], true)) {
                        $sawRunTerminal = true;
                    }
                    if (\in_array($type, ['compaction.completed', 'compaction.failed'], true)) {
                        return $events;
                    }
                }
                break;
            }

            if ($sawRunTerminal && microtime(true) - $lastEventAt > 0.8) {
                break;
            }

            usleep(50_000);
        }

        return $events;
    }

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
     * Find all auto compaction terminal event seqs (trigger=auto).
     *
     * Includes both context_compacted AND context_compaction_failed
     * — a failed auto-compaction (e.g. ineffective_compaction) is still
     * a terminal outcome that must not produce ghost LLM steps.
     *
     * @param list<array<string, mixed>> $coreEvents
     *
     * @return list<int>
     */
    private function findAutoCompactedSeqs(array $coreEvents): array
    {
        $seqs = [];
        $terminalTypes = ['context_compacted', 'context_compaction_failed'];
        foreach ($coreEvents as $evt) {
            if (!\in_array($evt['type'] ?? '', $terminalTypes, true)) {
                continue;
            }
            $trigger = $evt['payload']['trigger'] ?? '';
            if ('auto' === $trigger) {
                $seqs[] = (int) ($evt['seq'] ?? 0);
            }
        }

        return $seqs;
    }

    /**
     * Assert no ghost turn_advanced / leaf_set / llm_step_* events
     * occur after each auto compaction terminal (context_compacted
     * or context_compaction_failed with trigger=auto).
     *
     * @param list<array<string, mixed>> $coreEvents
     * @param list<int>                  $autoCompactedSeqs
     */
    private function assertNoGhostLlmAfterCompactionTerminals(
        array $coreEvents,
        array $autoCompactedSeqs,
        string $timeline,
    ): void {
        $compactedSeqSet = array_flip($autoCompactedSeqs);

        if ([] === $autoCompactedSeqs) {
            return;
        }

        $forbiddenTypes = ['turn_advanced', 'llm_step_completed', 'llm_step_failed', 'leaf_set'];
        $violations = [];
        $lastCompacted = $autoCompactedSeqs[\count($autoCompactedSeqs) - 1];

        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            $type = $evt['type'] ?? '';

            // Skip events at or before each compaction terminal.
            if ($seq <= $lastCompacted) {
                continue;
            }

            // After the last compaction, NO forbidden events may appear.
            if (\in_array($type, $forbiddenTypes, true)) {
                $trigger = $evt['payload']['trigger'] ?? '';
                $error = $evt['payload']['error'] ?? [];
                $violations[] = \sprintf(
                    '  seq=%d type=%s trigger=%s error_type=%s message=%s',
                    $seq,
                    $type,
                    $trigger,
                    $error['type'] ?? '',
                    $error['message'] ?? '',
                );
            }
        }

        $this->assertEmpty(
            $violations,
            "Auto compaction must not cause extra LLM turns.\n"
            ."Found post-compaction forbidden events (after last auto terminal seq {$lastCompacted}):\n"
            .implode("\n", $violations)."\n"
            ."\nFull timeline:\n".$timeline,
        );
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
            $usage = $evt['payload']['usage'] ?? [];
            $messagesCompacted = $evt['payload']['messages_compacted'] ?? '';
            $reason = $evt['payload']['reason'] ?? '';

            $extra = '';
            if ('auto' === $trigger) {
                $extra .= ' trigger=auto';
            }
            if ([] !== $usage) {
                $extra .= \sprintf(' input_tokens=%s', $usage['input_tokens'] ?? '?');
            }
            if ('' !== (string) $messagesCompacted) {
                $extra .= \sprintf(' compacted=%s', $messagesCompacted);
            }
            if ('' !== (string) $reason) {
                $extra .= \sprintf(' reason=%s', $reason);
            }

            $lines[] = \sprintf('  [%s] %s%s', $seq, $type, $extra);
        }

        return implode("\n", $lines);
    }
}
