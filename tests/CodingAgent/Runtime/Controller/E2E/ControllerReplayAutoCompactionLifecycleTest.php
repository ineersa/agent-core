<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Deterministic controller-replay proof for the auto-compaction lifecycle seam.
 *
 * Unit guards already cover predicates (threshold, summary-only, tool-cycle,
 * compactionResolved). This case exercises the real controller JSONL +
 * Messenger + provider-usage → CompactRun → continuation path:
 *
 *  1. threshold-triggered after-turn auto compaction completes
 *  2. no ghost LLM continuation after the auto terminal
 *  3. an explicit follow_up still proceeds on the same run
 *
 * Mid-tool-cycle races, repeated replication, and summary-only rejection remain
 * mapped to AutoCompactionHookSubscriberTest / CodingAgentPreLlmCompactionGuardTest.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class ControllerReplayAutoCompactionLifecycleTest extends ControllerReplayE2eTestCase
{
    private const string TURN1_MARKER = 'TURN1_COMPACT_THRESHOLD';
    private const string TURN2_MARKER = 'TURN2_AFTER_COMPACT';
    private const string GHOST_SENTINEL = 'BUG_GHOST_AFTER_AUTO_COMPACTION';

    public function testThresholdCompactionCompletesWithoutGhostThenFollowUpProceeds(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_start_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                // Long body + tiny keep_recent_tokens so SessionCompactor has a
                // compactable partition after the high provider-usage turn.
                'prompt' => self::TURN1_MARKER.' '.str_repeat(
                    'Automated testing is a fundamental practice. ',
                    20,
                ).'Respond with exactly: Understood.',
            ],
        ]);

        $turn1Events = $this->collectTurnEventsUntilRunTerminal(
            'run.completed',
            6.0,
            expectAfterTurnCompaction: true,
            compactionTimeoutSeconds: 3.0,
        );
        $turn1ByType = $this->indexByType($turn1Events);

        $this->assertStartRunAcked($turn1Events, $startCmdId);
        $this->assertArrayHasKey('run.started', $turn1ByType, $this->collectDiagnostics($turn1Events));

        $runStarted = $turn1ByType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId, 'run.started must include runId');

        if (isset($turn1ByType['run.failed'])) {
            $err = $turn1ByType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 1 failed unexpectedly: {$err}\n".$this->collectDiagnostics($turn1Events));
        }

        $this->assertArrayHasKey(
            'run.completed',
            $turn1ByType,
            'Turn 1 must reach run.completed. '.$this->collectDiagnostics($turn1Events),
        );
        $this->assertArrayHasKey(
            'compaction.completed',
            $turn1ByType,
            'After-turn auto compaction must emit compaction.completed. '.$this->collectDiagnostics($turn1Events),
        );

        $eventsPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/events.jsonl';
        $this->assertFileExists($eventsPath, 'Canonical events.jsonl must exist after compaction');

        $coreEvents = $this->loadCoreEvents($eventsPath);
        $timeline = $this->buildTimeline($coreEvents);
        $autoTerminalSeq = $this->findAutoCompactionTerminalSeq($coreEvents);

        $this->assertNotNull(
            $autoTerminalSeq,
            "events.jsonl must contain auto context_compacted/context_compaction_failed.\nTimeline:\n{$timeline}",
        );

        $autoTerminalEvt = null;
        foreach ($coreEvents as $evt) {
            if ((int) ($evt['seq'] ?? 0) === $autoTerminalSeq) {
                $autoTerminalEvt = $evt;
                break;
            }
        }
        $this->assertIsArray($autoTerminalEvt);
        $termType = (string) ($autoTerminalEvt['type'] ?? '');
        if ('context_compaction_failed' === $termType) {
            $reason = $autoTerminalEvt['payload']['reason'] ?? 'unknown';
            $this->fail(
                "Auto compaction failed (reason={$reason}); need successful context_compacted.\nTimeline:\n{$timeline}",
            );
        }
        $this->assertSame('context_compacted', $termType, "Timeline:\n{$timeline}");
        $payload = $autoTerminalEvt['payload'] ?? [];
        $this->assertSame('auto', $payload['trigger'] ?? '', 'context_compacted trigger must be auto');
        $this->assertGreaterThan(
            0,
            (int) ($payload['messages_compacted'] ?? 0),
            'context_compacted must report messages_compacted > 0',
        );

        $this->assertNoGhostContinuationAfter($coreEvents, $autoTerminalSeq, $timeline);

        $followUpCmdId = 'cmd_fu_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => self::TURN2_MARKER,
            ],
        ]);

        // No quiet drain: summary-only / no-retrigger predicates stay unit-covered.
        // This case only needs proof that an explicit follow_up still completes.
        $turn2Events = $this->collectEventsUntil('run.completed', 5.0);
        $turn2ByType = $this->indexByType($turn2Events);

        $this->assertTrue(
            $this->foundAck($turn2Events, $followUpCmdId),
            'Expected command.ack for follow_up. '.$this->collectDiagnostics($turn2Events),
        );
        $this->assertArrayNotHasKey(
            'command.rejected',
            $turn2ByType,
            'follow_up after auto compaction must not be rejected. '.$this->collectDiagnostics($turn2Events),
        );
        if (isset($turn2ByType['run.failed'])) {
            $err = $turn2ByType['run.failed'][0]['payload']['error'] ?? '?';
            $this->fail("Turn 2 failed unexpectedly: {$err}\n".$this->collectDiagnostics($turn2Events));
        }
        $this->assertArrayHasKey(
            'run.completed',
            $turn2ByType,
            'Follow-up after compaction must complete. '.$this->collectDiagnostics($turn2Events),
        );
        $this->assertTrue(
            $this->hasAssistantResponseEvidence($turn2ByType),
            'Follow-up after compaction must produce assistant output. '.$this->collectDiagnostics($turn2Events),
        );

        $coreEventsAfter = $this->loadCoreEvents($eventsPath);
        $timelineAfter = $this->buildTimeline($coreEventsAfter);
        $this->assertNoGhostContinuationAfter($coreEventsAfter, $autoTerminalSeq, $timelineAfter, untilUserTurnMarker: self::TURN2_MARKER);

        $encoded = json_encode($coreEventsAfter, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(
            self::GHOST_SENTINEL,
            $encoded,
            "Ghost FIFO fixture must not be consumed.\nTimeline:\n{$timelineAfter}",
        );
        $this->assertStringContainsString(
            self::TURN2_MARKER,
            $encoded,
            "Follow-up marker must appear in canonical events.\nTimeline:\n{$timelineAfter}",
        );
    }

    protected function tempDirPrefix(): string
    {
        return 'test-replay-auto-compact-lifecycle';
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
        return <<<'YAML'
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
            [
                '$schema' => 'Synthetic controller replay — turn 1 above compact_after_tokens',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'High input_tokens so after-turn auto compaction fires once.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
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
                'replay_match' => [
                    'last_user_contains' => self::TURN1_MARKER,
                ],
            ],
            [
                '$schema' => 'Synthetic controller replay — auto compaction summary',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Compaction LLM call matched via CONTEXT CHECKPOINT COMPACTION prompt.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => 'Context checkpoint: turn 1 acknowledged the compaction threshold prompt.'],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 12,
                    'total_tokens' => 612,
                ],
                'stop_reason' => 'stop',
                'replay_match' => [
                    'compaction_prompt' => true,
                ],
            ],
            [
                '$schema' => 'Synthetic controller replay — follow_up after compaction',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Below-threshold follow_up proves the run still advances after auto compaction.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => self::TURN2_MARKER],
                ],
                'usage' => [
                    'input_tokens' => 600,
                    'output_tokens' => 8,
                    'total_tokens' => 608,
                ],
                'stop_reason' => 'stop',
                'replay_match' => [
                    'last_user_contains' => self::TURN2_MARKER,
                ],
            ],
            // FIFO canary: consumed only if an unmatched LLM request slips through
            // after matcher fixtures (ghost continuation / unexpected compaction).
            [
                '$schema' => 'Synthetic controller replay — ghost canary (must not be consumed)',
                'fixture_source' => 'synthetic',
                'synthetic_reason' => 'Fail-loud canary for unexpected unmatched LLM calls after auto compaction.',
                'model' => 'llama_cpp_test/test',
                'provider_id' => 'llama_cpp_test',
                'reasoning' => 'off',
                'deltas' => [
                    ['type' => 'text', 'content' => self::GHOST_SENTINEL],
                ],
                'usage' => [
                    'input_tokens' => 700,
                    'output_tokens' => 10,
                    'total_tokens' => 710,
                ],
                'stop_reason' => 'stop',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $coreEvents
     */
    private function assertNoGhostContinuationAfter(
        array $coreEvents,
        int $autoTerminalSeq,
        string $timeline,
        ?string $untilUserTurnMarker = null,
    ): void {
        $stopSeq = null;
        if (null !== $untilUserTurnMarker) {
            foreach ($coreEvents as $evt) {
                $seq = (int) ($evt['seq'] ?? 0);
                if ($seq <= $autoTerminalSeq) {
                    continue;
                }
                $encoded = json_encode($evt, \JSON_THROW_ON_ERROR);
                if (str_contains($encoded, $untilUserTurnMarker)) {
                    $stopSeq = $seq;
                    break;
                }
            }
        }

        $forbidden = [];
        foreach ($coreEvents as $evt) {
            $seq = (int) ($evt['seq'] ?? 0);
            if ($seq <= $autoTerminalSeq) {
                continue;
            }
            if (null !== $stopSeq && $seq >= $stopSeq) {
                break;
            }
            $type = (string) ($evt['type'] ?? '');
            if (\in_array($type, ['turn_advanced', 'llm_step_completed', 'llm_step_failed', 'history_position_set'], true)) {
                $forbidden[] = \sprintf('seq=%d type=%s', $seq, $type);
            }
        }

        $this->assertEmpty(
            $forbidden,
            "Auto compaction must not cause ghost LLM continuation after seq {$autoTerminalSeq}"
            .(null !== $untilUserTurnMarker ? " before follow_up marker {$untilUserTurnMarker}" : '')
            .".\nFound:\n".implode("\n", $forbidden)
            ."\nTimeline:\n{$timeline}",
        );
    }

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
    private function findAutoCompactionTerminalSeq(array $coreEvents): ?int
    {
        $found = null;
        foreach ($coreEvents as $evt) {
            $type = (string) ($evt['type'] ?? '');
            if (!\in_array($type, ['context_compacted', 'context_compaction_failed'], true)) {
                continue;
            }
            if ('auto' !== ($evt['payload']['trigger'] ?? '')) {
                continue;
            }
            $found = (int) ($evt['seq'] ?? 0);
        }

        return $found;
    }

    /**
     * @param list<array<string, mixed>> $coreEvents
     */
    private function buildTimeline(array $coreEvents): string
    {
        $lines = [];
        foreach ($coreEvents as $evt) {
            $type = (string) ($evt['type'] ?? '?');
            $seq = $evt['seq'] ?? '?';
            $extra = '';
            $trigger = $evt['payload']['trigger'] ?? '';
            if ('' !== (string) $trigger) {
                $extra .= ' trigger='.$trigger;
            }
            $usage = $evt['payload']['usage'] ?? [];
            if (\is_array($usage) && isset($usage['input_tokens'])) {
                $extra .= ' input_tokens='.$usage['input_tokens'];
            }
            $messagesCompacted = $evt['payload']['messages_compacted'] ?? '';
            if ('' !== (string) $messagesCompacted) {
                $extra .= ' compacted='.$messagesCompacted;
            }
            $lines[] = \sprintf('  [%s] %s%s', $seq, $type, $extra);
        }

        return implode("\n", $lines);
    }
}
