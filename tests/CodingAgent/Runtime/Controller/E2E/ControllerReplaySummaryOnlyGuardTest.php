<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller replay — proves the after-turn summary-only
 * auto-compaction guard (session 14 class) is in place.
 *
 * Two-turn scenario with auto-compaction enabled:
 *   1. Turn 1 completes with provider usage above threshold →
 *      after-turn hook dispatches auto-compaction.
 *   2. First auto-compaction produces compact_summary.
 *   3. Short follow_up "ok" executes.  The pre-LLM guard does NOT fire
 *      because the latest provider measurement after the first auto-
 *      compaction is from the compaction LLM call (fixture 1,
 *      input_tokens=600, below compact_after_tokens=1000).  The
 *      follow_up LLM step consumes fixture 3 directly (fixture 2
 *      is not consumed — it acts as a canary).
 *   4. After the follow_up turn, the after-turn hook evaluates again.
 *      The session-14 bug: a pathological context_compacted appears
 *      with messages_compacted=1 and prior_summary_present=true.
 *      The fix: AutoCompactionHookSubscriber silently skips when
 *      prepare() returns a summary-only partition — no CompactRun
 *      is dispatched, no context_compaction_* events appear.
 *
 * Fixtures (5 total — two unconsumed canaries):
 *   0  Turn 1 assistant response (usage=5000 → triggers auto)
 *   1  First compaction summary (consumed by first after-turn hook)
 *   2  Pre-LLM compaction canary — NOT consumed in normal flow
 *      (latest provider usage after first compaction is 600,
 *      below the 1000 threshold, so pre-LLM guard does not fire).
 *      Consumed only if pre-LLM guard fires unexpectedly.
 *   3  Turn 2 assistant response (consumed by follow_up LLM step)
 *   4  GHOST — only consumed if after-turn hook fires on follow_up
 *      (session 14 regression: summary-only auto-compaction)
 *
 * The ghost fixture has input_tokens=700 (below threshold) so even if
 * consumed accidentally it won't cascade into yet another compaction.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplaySummaryOnlyGuardTest extends ControllerReplayE2eTestCase
{
    // ─────────────────────────────────────────────────────────────────
    //  Single test method
    // ─────────────────────────────────────────────────────────────────

    public function testSummaryOnlyAutoCompactionIsPreventedAfterFollowUp(): void
    {
        $this->spawnController();

        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // ═════════════════════════════════════════════════════════════
        //  Phase 1 — First turn + auto-compaction
        // ═════════════════════════════════════════════════════════════

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => str_repeat(
                    'Automated testing is a fundamental practice. ',
                    20,
                ).'Now respond with exactly: Understood.',
            ],
        ]);

        $turn1Events = $this->collectTurnEventsUntilRunTerminal('run.completed', 8.0, expectAfterTurnCompaction: true, compactionTimeoutSeconds: 6.0);
        $t1ByType = $this->indexByType($turn1Events);

        $this->assertStartRunAcked($turn1Events, $startCmdId);

        $this->assertArrayHasKey('run.started', $t1ByType,
            'Expected run.started after start_run');

        $runStarted = $t1ByType['run.started'][0];
        $this->runId = (string) ($runStarted['runId']
            ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        if (isset($t1ByType['run.failed'])) {
            $err = $t1ByType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 1 run failed: {$err}\n"
                .$this->collectDiagnostics($turn1Events));
        }

        $this->assertArrayHasKey('run.completed', $t1ByType,
            'Turn 1 must reach run.completed.');

        // ═════════════════════════════════════════════════════════════
        //  Phase 2 — Follow-up
        // ═════════════════════════════════════════════════════════════

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => ['text' => 'ok'],
        ]);

        $turn2Events = $this->collectTurnEventsUntilRunTerminal('run.completed', 8.0, expectAfterTurnCompaction: false);
        $turn2Events = array_merge(
            $turn2Events,
            $this->drainUntilCompactionQuiet(1.5),
        );
        $t2ByType = $this->indexByType($turn2Events);

        $this->assertTrue(
            $this->foundAck($turn2Events, $followUpCmdId),
            'Expected command.ack for follow_up.');

        if (isset($t2ByType['run.failed'])) {
            $err = $t2ByType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 2 run failed: {$err}\n"
                .$this->collectDiagnostics($turn2Events));
        }

        $this->assertArrayHasKey('run.completed', $t2ByType,
            'Turn 2 must reach run.completed after follow_up.');

        // ═════════════════════════════════════════════════════════════
        //  Phase 3 — Structural proof from events.jsonl
        // ═════════════════════════════════════════════════════════════

        $eventsPath = $this->tempDir.'/.hatfield/sessions/'
            .$this->sessionId.'/events.jsonl';
        $this->assertFileExists($eventsPath);

        $coreEvents = $this->loadCoreEvents($eventsPath);
        $timeline = $this->buildTimeline($coreEvents);

        // Find all after-turn auto context_compacted events
        // (trigger=auto, continue_after_compaction=false).
        $afterTurnCompacted = $this->findAfterTurnAutoCompactedSeqs($coreEvents);

        $this->assertNotEmpty(
            $afterTurnCompacted,
            "Must have at least one after-turn auto context_compacted.\n"
            ."Timeline:\n".$timeline,
        );

        // Check that NO after-turn context_compacted is summary-only.
        // "Summary-only" = messages_compacted=1 AND
        //                   prior_summary_present=true
        $summaryOnly = [];
        foreach ($afterTurnCompacted as $evt) {
            $cp = $evt['payload'] ?? [];
            $isSummaryOnly = 1 === ($cp['messages_compacted'] ?? 0)
                && true === ($cp['prior_summary_present'] ?? false);

            if ($isSummaryOnly) {
                $summaryOnly[] = $evt;
            }
        }

        $this->assertEmpty(
            $summaryOnly,
            'SESSION 14 REGRESSION: after-turn auto context_compacted '
            .'(continue_after_compaction=false) with '
            .'messages_compacted=1 AND prior_summary_present=true '
            .'must NOT appear.  Found '.\count($summaryOnly)
            .' summary-only event(s).'
            ."\nTimeline:\n".$timeline,
        );

        // ═════════════════════════════════════════════════════════════
        //  Phase 4 — GHOST fixture NOT consumed
        // ═════════════════════════════════════════════════════════════
        //
        // The ghost fixture (index 4) carries distinctive text and
        // would only be consumed if the after-turn guard fires another
        // auto-compaction.  Verify the ghost text is absent from the
        // compacted events.
        $this->assertStringNotContainsString(
            'BUG: summary-only compaction fired on session 14',
            json_encode($coreEvents),
            'GHOST fixture was consumed — the after-turn hook dispatched '
            .'a summary-only auto-compaction.  The guard must prevent this.'
            ."\nTimeline:\n".$timeline,
        );

        // Also verify the ghost fixture was not consumed by checking
        // no llm_step_completed references the ghost output text via
        // context_compacted summary_text.
        foreach ($coreEvents as $evt) {
            if ('context_compacted' !== ($evt['type'] ?? '')) {
                continue;
            }
            $cp = $evt['payload'] ?? [];
            $summary = (string) ($cp['summary_text'] ?? '');
            $this->assertStringNotContainsString(
                'BUG: summary-only',
                $summary,
                'GHOST compaction summary found in context_compacted — '
                .'session 14 regression is present.'
                ."\nTimeline:\n".$timeline,
            );
        }
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-summary-guard';
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
    keep_recent_tokens: 3
YAML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [
            // ═══════════════════════════════════════════════════════
            //  Fixture 0 — Turn 1 assistant response
            // ═══════════════════════════════════════════════════════
            //
            // usage.input_tokens=5000 > compact_after_tokens=1000.
            // After this turn completes, the after-turn hook dispatches
            // auto-compaction.
            [
                '$schema' => 'Synthetic controller replay — session 14 turn 1',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Turn 1 LLM response with high input_tokens '
                    .'to trigger after-turn auto-compaction.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Understood.'],
                ],
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 5,
                    'total_tokens' => 5005,
                ],
                'stop_reason' => 'stop',
            ],

            // ═══════════════════════════════════════════════════════
            //  Fixture 1 — First compaction summary
            // ═══════════════════════════════════════════════════════
            //
            // Consumed by the after-turn auto-compaction LLM call.
            [
                '$schema' => 'Synthetic controller replay — first compaction',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Compaction LLM call after turn 1.  '
                    .'Produces a compact_summary in the message list.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: the conversation introduced automated testing practices.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 10,
                    'total_tokens' => 610,
                ],
                'stop_reason' => 'stop',
            ],

            // ═══════════════════════════════════════════════════════
            //  Fixture 2 — Pre-LLM compaction canary (NOT consumed)
            // ═══════════════════════════════════════════════════════
            //
            // NOT consumed in normal flow.  After the first auto-
            // compaction completes, the latest provider usage is from
            // the compaction LLM call (fixture 1, input_tokens=600).
            // 600 <= compact_after_tokens=1000, so the pre-LLM guard
            // in AdvanceRunHandler does NOT fire before the follow_up
            // LLM step.  Fixture 3 is consumed directly as the
            // follow_up assistant response.
            //
            // If this fixture IS consumed, the pre-LLM guard fired
            // when it shouldn't have — a different regression.
            [
                '$schema' => 'Synthetic controller replay — pre-LLM canary (not consumed)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Pre-LLM compaction canary: not consumed because latest '
                    .'provider usage (600) is below threshold (1000) after first auto-compaction.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: automated testing improves code quality through systematic verification.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 10,
                    'total_tokens' => 610,
                ],
                'stop_reason' => 'stop',
            ],

            // ═══════════════════════════════════════════════════════
            //  Fixture 3 — Turn 2 assistant response (follow_up)
            // ═══════════════════════════════════════════════════════
            //
            // Consumed by the follow_up LLM step (fixture 2 was NOT
            // consumed — pre-LLM guard did not fire).
            //
            // The response is deliberately long so keep_recent_tokens=3
            // (≈10 chars) produces a boundary that puts compact_summary1
            // and the earlier messages into the compact range while
            // keeping "ok" + this response in the tail — yielding a
            // summary-only after-turn partition that the guard must
            // silently reject.
            [
                '$schema' => 'Synthetic controller replay — turn 2 follow_up',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Follow_up LLM response.  Long text ensures '
                    .'keep_recent_tokens=3 retains only the last few chars '
                    .'in the tail, pushing all prior messages (including '
                    .'compact_summary1) into the compact range for the '
                    .'summary-only after-turn check.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Manual testing complements automated approaches by enabling creative exploration and usability assessment that scripted checks cannot provide, though it is slower and less consistent across different testers and environments.'],
                ],
                'usage' => [
                    'input_tokens' => 6000,
                    'output_tokens' => 30,
                    'total_tokens' => 6030,
                ],
                'stop_reason' => 'stop',
            ],

            // ═══════════════════════════════════════════════════════
            //  Fixture 4 — GHOST (session 14 regression path)
            // ═══════════════════════════════════════════════════════
            //
            // BUG: if the after-turn hook on the follow_up turn
            // dispatches a summary-only auto-compaction, the
            // controller consumes THIS fixture.  On a working guard,
            // this fixture is NEVER consumed.
            //
            // input_tokens=700 is below compact_after_tokens=1000 so
            // even if consumed accidentally it won't cascade.
            [
                '$schema' => 'Synthetic controller replay — GHOST (session 14 bug)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'BUG: if after-turn hook fires summary-only '
                    .'compaction, this fixture is consumed.  Guard must prevent it.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'BUG: summary-only compaction fired on session 14.  This fixture is consumed only when the guard is missing.'],
                ],
                'usage' => [
                    'input_tokens' => 700,
                    'output_tokens' => 20,
                    'total_tokens' => 720,
                ],
                'stop_reason' => 'stop',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────
    //  Local helpers
    // ─────────────────────────────────────────────────────────────────

    /**
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
     * @param list<array<string, mixed>> $coreEvents
     */
    private function buildTimeline(array $coreEvents): string
    {
        $lines = [];
        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '?';
            $seq = $evt['seq'] ?? '?';
            $trigger = $evt['payload']['trigger'] ?? '';
            $usg = $evt['payload']['usage'] ?? [];
            $it = $usg['input_tokens'] ?? '?';
            $mc = $evt['payload']['messages_compacted'] ?? '';
            $mr = $evt['payload']['messages_retained'] ?? '';
            $ps = isset($evt['payload']['prior_summary_present'])
                ? ($evt['payload']['prior_summary_present'] ? 'Y' : 'N') : '-';
            $cac = $evt['payload']['continue_after_compaction'] ?? null;
            $cacStr = null !== $cac ? ($cac ? 'T' : 'F') : '-';

            $extra = '';
            if ('' !== $trigger) {
                $extra .= " trigger={$trigger}";
            }
            if ('?' !== (string) $it) {
                $extra .= " it={$it}";
            }
            if ('' !== $mc) {
                $extra .= " mc={$mc}";
            }
            if ('' !== $mr) {
                $extra .= " mr={$mr}";
            }
            $extra .= " ps={$ps} cac={$cacStr}";

            $lines[] = "seq {$seq}: {$type}{$extra}";
        }

        return implode("\n", $lines);
    }

    /**
     * Find all after-turn auto context_compacted events
     * (trigger=auto AND continue_after_compaction=false).
     *
     * @param list<array<string, mixed>> $coreEvents
     *
     * @return list<array<string, mixed>>
     */
    private function findAfterTurnAutoCompactedSeqs(array $coreEvents): array
    {
        $result = [];
        foreach ($coreEvents as $evt) {
            if ('context_compacted' !== ($evt['type'] ?? '')) {
                continue;
            }

            $cp = $evt['payload'] ?? [];
            if ('auto' !== ($cp['trigger'] ?? null)) {
                continue;
            }

            // Only after-turn compactions — exclude pre-LLM guard path.
            if (false !== ($cp['continue_after_compaction'] ?? true)) {
                continue;
            }

            $result[] = $evt;
        }

        return $result;
    }
}
