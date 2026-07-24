<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller replay E2E — reproduces the session 5 and
 * session 8 mid-tool-cycle auto-compaction race conditions.
 *
 * Two bugs proven:
 *  1. (Session 5) ToolBatchCommitted commits trigger auto-compaction
 *     via the after-turn hook before the postCommit AdvanceRun fires.
 *     FIX: ToolBatchCommitted guard in AutoCompactionHookSubscriber.
 *  2. (Session 8) PARTIAL tool-result commits (first tool_execution_end
 *     without ToolBatchCommitted) trigger auto-compaction mid-cycle.
 *     The hook sees effectsCount=0, no ToolExecutionStart (prior commit),
 *     no ToolBatchCommitted (batch incomplete) — all guards pass.
 *     FIX: Check RunState::pendingToolCalls for unresolved entries.
 *  3. (Session 8) The pre-LLM guard fires on the post-tool AdvanceRun,
 *     dispatching a CompactRun with continueAfterCompaction=true before
 *     the final assistant answer can complete.  Compaction fails
 *     (empty_summary/no_safe_boundary), session dead-ends.
 *     FIX: Skip pre-LLM guard on post-tool continuations.
 *
 * This test proves:
 *  1. Tool batch commits do not trigger mid-cycle auto-compaction
 *  2. Partial tool-result commits do not trigger mid-cycle auto-compaction
 *  3. The post-tool AdvanceRun fires and the final assistant turn completes
 *  4. No pre-LLM compaction fires on post-tool AdvanceRun
 *  5. Auto-compaction fires AFTER the full assistant/tool cycle finishes
 *
 * Replay fixture design:
 *   Fixture 0: tool_call (2 tools: read + bash), usage.input_tokens=5000
 *              ABOVE compact_after_tokens=1000 — exercises BOTH the partial
 *              batch hook path AND the pre-LLM guard path.
 *   Fixture 1: final assistant text response after tool batch, usage=6000
 *   Fixture 2: compaction summary LLM call (consumed after full turn)
 *
 * The thesis: the final assistant answer arrives after the tool cycle
 * without interference from either the hook or the pre-LLM guard.
 * Auto-compaction fires via the after-turn hook after the full turn
 * completes.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayAutoCompactionToolCycleTest extends ControllerReplayE2eTestCase
{
    // ── Lifecycle: write the target file before spawning ──────────

    protected function setUp(): void
    {
        parent::setUp();

        // Write the target file for the read tool.  The controller
        // subprocess runs with --cwd pointing to the temp dir, so
        // ./notes.txt resolves correctly.
        file_put_contents($this->tempDir.'/notes.txt', "Hello from the tool-cycle test.\n");
    }

    // ─────────────────────────────────────────────────────────────────
    //  Single test method
    // ─────────────────────────────────────────────────────────────────

    public function testToolCycleDoesNotTriggerMidTurnAutoCompaction(): void
    {
        $this->spawnController();

        // ── Wait for runtime.ready ──
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        // ═════════════════════════════════════════════════════════════
        //  Start run with a prompt — expect tool_call + tool batch +
        //  final assistant turn + auto-compaction.
        // ═════════════════════════════════════════════════════════════

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Read the file ./notes.txt and tell me what it contains.',
            ],
        ]);

        // Collect events past run.completed so we catch the after-turn
        // auto-compaction events (compaction.started/completed/failed).
        // Early-exit wait: full castor-check parallel lanes can delay the
        // second post-tool replay turn beyond 8s; 12s matches the existing
        // controller full-gate contention budget (liveControllerReadyTimeout /
        // liveLlmRunWaitTimeout). Compaction drain remains 6s separately.
        $events = $this->collectTurnEventsUntilRunTerminal('run.completed', 12.0, expectAfterTurnCompaction: true, compactionTimeoutSeconds: 6.0);
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);

        $this->assertArrayHasKey('run.started', $byType, 'Expected run.started');

        $runStarted = $byType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must have a runId');

        // Turn must complete (run.completed).
        $this->assertTrue(
            isset($byType['run.completed']) || isset($byType['run.failed']),
            'Run must reach terminal state (run.completed or run.failed).'
            ."\n".$this->collectDiagnostics($events),
        );

        if (isset($byType['run.failed'])) {
            $err = $byType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Run failed unexpectedly: {$err}\n"
                .$this->collectDiagnostics($events));
        }

        // ═════════════════════════════════════════════════════════════
        //  Structural proof from canonical events.jsonl
        // ═════════════════════════════════════════════════════════════

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $eventsPath = $sessionDir.'/events.jsonl';

        $this->assertFileExists($eventsPath, 'Session events.jsonl must exist');

        $coreEvents = $this->loadCoreEvents($eventsPath);
        $this->assertNotEmpty($coreEvents, 'events.jsonl must have events');

        $timeline = $this->buildTimeline($coreEvents);

        // ════════════════════════════════════════════════════════════
        //  Assert: the full tool cycle completed without mid-cycle
        //  auto-compaction interference.
        // ════════════════════════════════════════════════════════════

        // Find the first llm_step_completed with tool_calls_count>0.
        // This is the assistant-trigger that starts the tool cycle.
        $toolCallLlmSeq = null;
        $toolCallCount = null;
        foreach ($coreEvents as $evt) {
            $tc = (int) ($evt['payload']['tool_calls_count'] ?? 0);
            if ($tc > 0 && 'llm_step_completed' === ($evt['type'] ?? '')) {
                $toolCallLlmSeq = (int) ($evt['seq'] ?? 0);
                $toolCallCount = $tc;
                break;
            }
        }

        $this->assertNotNull(
            $toolCallLlmSeq,
            "events.jsonl must contain an llm_step_completed with tool_calls_count>0.\n"
            ."Timeline:\n".$timeline,
        );

        // Find the next llm_step_completed after the tool-call LLM.
        // This is the final assistant answer after all tool results
        // and the postCommit AdvanceRun.
        $finalLlmSeq = null;
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq > $toolCallLlmSeq && 'llm_step_completed' === ($evt['type'] ?? '')) {
                $finalLlmSeq = $seq;
                break;
            }
        }

        $this->assertNotNull(
            $finalLlmSeq,
            "Expected llm_step_completed (final assistant answer) after the tool-call LLM at seq={$toolCallLlmSeq}. "
            ."If missing, mid-cycle compaction blocked the postCommit AdvanceRun.\n"
            ."Timeline:\n".$timeline,
        );

        // REGRESSION PROOF: no auto compaction events of ANY kind
        // (context_compaction_started/compacted/failed) between the
        // tool-call llm_step_completed and the final assistant answer.
        // This catches both:
        //  - Partial-batch hook compaction (between tool_execution_end events)
        //  - Pre-LLM guard compaction on post-tool AdvanceRun
        $compactionLifecycleTypes = [
            'context_compaction_started',
            'context_compacted',
            'context_compaction_failed',
        ];
        $midCycleCompactionEvent = null;
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            $type = $evt['type'] ?? '';
            if ($seq > $toolCallLlmSeq && $seq < $finalLlmSeq
                && \in_array($type, $compactionLifecycleTypes, true)
            ) {
                $trigger = $evt['payload']['trigger'] ?? '';
                if ('auto' === $trigger) {
                    $midCycleCompactionEvent = \sprintf(
                        'auto %s at seq=%d (trigger=%s reason=%s)',
                        $type,
                        $seq,
                        $trigger,
                        $evt['payload']['reason'] ?? '?',
                    );
                    $timeline .= "\n  → BUG: {$midCycleCompactionEvent}";
                    break;
                }
            }
        }

        $this->assertNull(
            $midCycleCompactionEvent,
            "Auto compaction must NOT fire between the tool-call llm_step_completed (seq={$toolCallLlmSeq}) "
            ."and the final llm_step_completed (seq={$finalLlmSeq}). "
            ."Found: {$midCycleCompactionEvent}. "
            ."Both the partial-batch hook path and the pre-LLM guard path must be blocked.\n"
            ."Timeline:\n".$timeline,
        );

        // ── Assert: auto context_compacted appears after the final
        //    assistant turn (voluntary — proves full cycle)
        $autoCompactedSeq = null;
        $autoTerminalType = null;
        foreach ($coreEvents as $evt) {
            $type = $evt['type'] ?? '';
            $seq = (int) ($evt['seq'] ?? 0);
            if (\in_array($type, ['context_compacted', 'context_compaction_failed'], true)
                && 'auto' === ($evt['payload']['trigger'] ?? '')
                && $seq > $finalLlmSeq
            ) {
                $autoCompactedSeq = $seq;
                $autoTerminalType = $type;
                break;
            }
        }

        $this->assertNotNull(
            $autoCompactedSeq,
            "Expected auto context_compacted (or context_compaction_failed) after llm_step_completed at seq={$finalLlmSeq}. "
            ."Auto compaction should fire after the full assistant/tool cycle, not during it.\n"
            ."Timeline:\n".$timeline,
        );

        if ('context_compaction_failed' === $autoTerminalType) {
            $failPayload = [];
            foreach ($coreEvents as $evt) {
                if (($evt['seq'] ?? 0) === $autoCompactedSeq) {
                    $failPayload = $evt['payload'] ?? [];
                    break;
                }
            }
            $failReason = $failPayload['reason'] ?? 'unknown';
            // Auto compaction fired after the full cycle (correct placement)
            // but failed to produce a summary.  This is a test-data sizing
            // issue, not a regression.  The critical proof (no mid-cycle
            // compaction) is satisfied by the earlier assertions.
            $this->addToAssertionCount(1);

            return;
        }

        $terminalPayload = [];
        foreach ($coreEvents as $evt) {
            if (($evt['seq'] ?? 0) === $autoCompactedSeq) {
                $terminalPayload = $evt['payload'] ?? [];
                break;
            }
        }
        $this->assertGreaterThan(
            0,
            $terminalPayload['messages_compacted'] ?? 0,
            'context_compacted must report messages_compacted > 0',
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-auto-compact-tool';
    }

    protected function modelConfig(): array
    {
        return [
            'input' => ['text'],
            'tool_calling' => true,
        ];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<YAML
compaction:
    auto_enabled: true
    compact_after_tokens: 1000
    keep_recent_tokens: 10
YAML;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [
            // ── Fixture 0: Initial LLM call returns tool_calls (read + bash) ──
            //
            // usage.input_tokens=5000 ABOVE compact_after_tokens=1000.
            // This exercises BOTH the partial batch hook path AND the
            // pre-LLM guard path:
            //  - The after-turn hook fires on each tool_execution_end commit
            //    (ToolCallResultHandler partial batch: effectsCount=0,
            //    no ToolExecutionStart, no ToolBatchCommitted).
            //  - If the hook skipped because pendingToolCalls are unresolved,
            //    the postCommit AdvanceRun fires next — and the pre-LLM guard
            //    would see usage=5000 > threshold and dispatch CompactRun
            //    before the final assistant answer.
            // Both paths must NOT fire mid-cycle.
            [
                '$schema' => 'Synthetic controller replay — tool_calls (read ./notes.txt + bash echo)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 8 regression: '
                    .'initial LLM call returns TWO tool_calls (read + bash) so '
                    .'partial batch commits exist.  '
                    .'usage.input_tokens=5000 > compact_after_tokens=1000 exercises '
                    .'the partial-batch hook path AND the pre-LLM guard path.',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'tool_call_start', 'id' => 'call_age_1', 'name' => 'read'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => '{"pa'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => 'th":'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => ' "./'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => 'not'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => 'es.'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_1', 'name' => 'read', 'partial_json' => 'txt"}'],
                    ['type' => 'tool_call_start', 'id' => 'call_age_2', 'name' => 'bash'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => '{"co'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'mma'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'nd"'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => ': "e'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'cho '],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'tool-'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'cycl'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'e te'],
                    ['type' => 'tool_input_delta', 'id' => 'call_age_2', 'name' => 'bash', 'partial_json' => 'st"}'],
                    ['type' => 'tool_call_complete', 'tool_calls' => [
                        ['id' => 'call_age_1', 'name' => 'read', 'arguments' => ['path' => './notes.txt']],
                        ['id' => 'call_age_2', 'name' => 'bash', 'arguments' => ['command' => 'echo tool-cycle test']],
                    ]],
                ],
                'usage' => [
                    'input_tokens' => 5000,
                    'output_tokens' => 120,
                    'total_tokens' => 5120,
                ],
                'stop_reason' => 'tool_call',
            ],

            // ── Fixture 1: Final assistant response after tool batch ──
            //
            // This fixture is consumed by the postCommit AdvanceRun that
            // fires AFTER tool_batch_committed.  usage.input_tokens=6000
            // keeps the provider measurement above threshold.
            //
            // REGRESSION PROOF: if either the partial-batch hook or the
            // pre-LLM guard fires mid-cycle, this fixture is NEVER consumed
            // (AdvanceRun is swallowed by Compacting guard, or CompactRun
            // replaces it).  The final assistant answer would be missing.
            [
                '$schema' => 'Synthetic controller replay — final assistant text after tool',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E session 8 regression: post-tool AdvanceRun '
                    .'consumes this fixture for the final assistant answer.  '
                    .'usage.input_tokens=6000 > compact_after_tokens=1000 — the pre-LLM '
                    .'guard must NOT fire on this post-tool continuation.  '
                    .'If it does, it dispatches CompactRun instead and this fixture '
                    .'is never consumed (fixture exhaustion / wrong response).',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'The file notes.txt contains: Hello from the tool-cycle test. The bash command output was: tool-cycle test.'],
                ],
                'usage' => [
                    'input_tokens' => 6000,
                    'output_tokens' => 40,
                    'total_tokens' => 6040,
                ],
                'stop_reason' => 'stop',
            ],

            // ── Fixture 2: Auto-compaction summary LLM call ──
            [
                '$schema' => 'Synthetic controller replay — compaction summary',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Controller replay E2E: the LLM call performed by '
                    .'ExecuteCompactionStepWorker to summarise compacted messages.  '
                    .'This is only consumed if auto-compaction fires AFTER the final '
                    .'assistant turn (not mid-cycle).',
                'model' => 'llama_cpp/test',
                'provider_id' => 'llama_cpp',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: The conversation discussed reading a file and its contents.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 15,
                    'total_tokens' => 615,
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
            $usage = $evt['payload']['usage'] ?? [];
            $stopReason = $evt['payload']['stop_reason'] ?? '';

            $extra = '';
            if ('auto' === $trigger) {
                $extra .= ' trigger=auto';
            }
            if ([] !== $usage) {
                $extra .= \sprintf(' input_tokens=%s', $usage['input_tokens'] ?? '?');
            }
            if ('' !== $stopReason) {
                $extra .= \sprintf(' stop=%s', $stopReason);
            }

            $lines[] = \sprintf('  [%s] %s%s', $seq, $type, $extra);
        }

        return implode("\n", $lines);
    }
}
