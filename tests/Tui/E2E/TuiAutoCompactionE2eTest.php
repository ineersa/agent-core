<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that auto-compaction becomes visible in the actual TUI flow.
 *
 * Configures a very low compact_after_tokens threshold so the auto-compaction
 * hook fires after the first turn commit.  Asserts the user-visible
 * "Compacting conversation..." progress message appears without manual /compact.
 *
 * Design:
 *  - Single tmux session with APP_ENV=test + replay fixture for model interaction.
 *  - Isolated project dir with compaction.auto_enabled=true, compact_after_tokens=10.
 *  - Submit prompt, receive assistant response (replay fixture).
 *  - Verify "Compacting conversation..." appears (auto trigger via HookSubscriberInterface).
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiAutoCompactionE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Auto-compaction triggers after first turn and is visible in TUI.
     *
     * Asserts:
     *  1. Prompt submission works (response block appears).
     *  2. After the turn commit, "Compacting conversation..." appears
     *     WITHOUT typing /compact (proving auto trigger path).
     */
    public function testAutoCompactionTriggeredAndVisibleInTui(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-auto-compact',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (logo visible).
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a prompt.  The replay fixture provides a response
            // whose token count exceeds the 10-token auto threshold.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $prompt = 'Respond with a paragraph about AI agents.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant response (◇ block or ✕ error block).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 15.0,
                message: 'Assistant response block did not appear',
                history: 2000,
            );

            // After the turn commits, the AutoCompactionHookSubscriber
            // fires and dispatches CompactRun(trigger: 'auto').  The
            // CompactionProjectionSubscriber renders a visible block —
            // either "Compacting conversation..." (transient progress),
            // "⧉ Conversation compacted" (success), or "Compaction failed"
            // (structural failure).  ANY of these proves the auto trigger
            // path is functional end-to-end (hook → dispatch → handler →
            // runtime events → projection → visible TUI) without typing
            // /compact.
            //
            // With the provider-usage-based trigger, the replay fixture's
            // input_tokens (17) exceed compact_after_tokens (10), so auto-
            // compaction triggers and typically succeeds on the tiny
            // fixture session.
            $autoCompactCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation')
                    || str_contains($cap, 'Conversation compacted')
                    || str_contains($cap, 'Compaction failed'),
                timeout: 20.0,
                message: 'Auto-compaction progress/failure not shown in TUI',
                history: 2000,
            );

            self::assertThat(
                $autoCompactCapture,
                self::logicalOr(
                    self::stringContains('Compacting conversation'),
                    self::stringContains('Conversation compacted'),
                    self::stringContains('Compaction failed'),
                ),
                'Auto-compaction must produce visible compaction-related text in TUI without manual /compact',
            );

            // Post-compaction: wait for any retry mechanics to settle
            // so the log is complete before inspection.
            usleep(500_000);

            // ── Structural proof: no concurrent auto compaction starts ──
            // The live-duplicate bug caused two context_compaction_started
            // events with trigger=auto to appear without a terminal event
            // (context_compacted or context_compaction_failed) between them.
            // The RunStatus::Compacting fix in ace4f906d prevents this by
            // blocking concurrent AdvanceRun/CompactRun dispatches while a
            // compaction is in flight.
            //
            // This assertion proves no concurrent starts — each auto start
            // is followed by a terminal before the next auto start.
            $this->assertNoConcurrentAutoCompactionStarts();

            // ── Structural proof: exactly one auto-compaction lifecycle outcome ──
            // The event-log eligibility (ProviderContextUsageResolver) prevents
            // stale provider measurements from re-triggering auto-compaction.
            // After the first auto compaction starts (context_compaction_started
            // with trigger=auto), that provider measurement is marked handled
            // and cannot re-trigger — regardless of in-memory dedup maps or
            // process restarts.
            //
            // A second sequential auto compaction would only be legitimate if
            // a NEWER provider measurement (new llm_step_completed with higher
            // seq) arrives above the threshold after the first compaction.
            // With compact_after_tokens=10 + tiny fixture, the first compaction
            // typically drops tokens below threshold.
            $this->assertExactlyOneAutoCompactionOutcome();

            // ── Sanity check: no boot/bus errors from double dispatch ──
            // The auto-compaction hook dispatches CompactRun on
            // agent.command.bus from inside RunCommit.  Without the fix
            // that removes CompactRun from transport routing, the
            // SendMessageMiddleware re-routes it to run_control (sync://)
            // and SyncTransport re-dispatches through RoutableMessageBus,
            // which can land on agent.execution.bus where no handler
            // exists, producing a NoHandlerForMessageException retry loop.
            //
            // Assert the log AND the TUI capture are free of that error.
            $finalCapture = $this->tmux->captureAnsi($pane);
            self::assertStringNotContainsString(
                'Run failed',
                $finalCapture,
                'TUI must not show "Run failed" after auto-compaction (hidden bus error)',
            );
            self::assertStringNotContainsString(
                'No handler',
                $finalCapture,
                'TUI must not show "No handler" after auto-compaction',
            );

            $this->assertNoBusErrorInLog();

            // Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'auto-compact-success');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'auto-compact-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Assert no two auto context_compaction_started events appear without
     * a terminal event (context_compacted or context_compaction_failed)
     * between them.  This is the concurrent-starts invariant that the
     * RunStatus::Compacting fix protects.
     *
     * The live-duplicate bug caused multiple context_compaction_started
     * events with trigger=auto to stack without intervening terminal events
     * because the run was not in a dedicated Compacting lifecycle and
     * follow-up commands could re-advance the run concurrently.
     */
    private function assertNoConcurrentAutoCompactionStarts(): void
    {
        $eventLog = $this->testProjectDir.'/.hatfield/sessions/1/events.jsonl';

        if (!\is_file($eventLog)) {
            self::fail('events.jsonl not found at '.$eventLog.' — TUI session did not produce expected event log.');
        }

        $lines = \file($eventLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        $terminalTypes = ['context_compacted', 'context_compaction_failed'];
        $pendingAutoStart = null;

        foreach ($lines as $line) {
            $event = \json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            $payload = $event['payload'] ?? [];
            $trigger = $payload['trigger'] ?? null;
            $seq = $event['seq'] ?? 0;

            if ('context_compaction_started' === $type && 'auto' === $trigger) {
                if (null !== $pendingAutoStart) {
                    self::fail(\sprintf(
                        'Concurrent auto-compaction starts detected: seq %d started while seq %d had no terminal event. '
                        .'The RunStatus::Compacting fix should prevent this by blocking concurrent advance/compact dispatches.',
                        $seq,
                        $pendingAutoStart,
                    ));
                }
                $pendingAutoStart = $seq;
            }

            if (\in_array($type, $terminalTypes, true) && 'auto' === $trigger) {
                $pendingAutoStart = null;
            }
        }

        // No assertion needed if we reach here — no concurrent starts found.
        self::assertTrue(true); // PHPUnit requires at least one assertion.
    }

    /**
     * Assert exactly one auto-compaction lifecycle terminal outcome.
     *
     * The event-log eligibility fix (ProviderContextUsageResolver) prevents
     * stale provider measurements from re-triggering auto-compaction.
     * After context_compaction_started with trigger=auto, the matching
     * provider measurement is marked handled — no second auto compaction
     * can fire for the same measurement, even after in-memory dedup maps
     * are cleared.
     *
     * With compact_after_tokens=10 on a tiny replay fixture, the first
     * compaction drops tokens below threshold, producing exactly one outcome.
     *
     * A second sequential outcome would only appear if a NEWER provider
     * measurement (new llm_step_completed with higher seq) exceeds the
     * threshold — this is legitimate but does not happen on this tiny fixture.
     */
    private function assertExactlyOneAutoCompactionOutcome(): void
    {
        $eventLog = $this->testProjectDir.'/.hatfield/sessions/1/events.jsonl';

        if (!\is_file($eventLog)) {
            self::fail('events.jsonl not found at '.$eventLog.' — TUI session did not produce expected event log.');
        }

        $lines = \file($eventLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $autoOutcomes = [];

        $terminalTypes = ['context_compacted', 'context_compaction_failed'];

        foreach ($lines as $line) {
            $event = \json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            if (!\in_array($type, $terminalTypes, true)) {
                continue;
            }

            $payload = $event['payload'] ?? [];
            $trigger = $payload['trigger'] ?? null;

            if ('auto' === $trigger) {
                $autoOutcomes[] = [
                    'seq' => $event['seq'] ?? 0,
                    'type' => $type,
                    'reason' => $payload['reason'] ?? 'n/a',
                ];
            }
        }

        $count = \count($autoOutcomes);
        self::assertEquals(
            1,
            $count,
            \sprintf(
                'Expected exactly 1 auto-compaction lifecycle outcome (stale measurement blocked by event-log eligibility), found %d: %s',
                $count,
                \json_encode($autoOutcomes),
            ),
        );
    }

    private function assertNoBusErrorInLog(): void
    {
        $logGlob = $this->testProjectDir.'/.hatfield/logs/agent-*.log';
        $logFiles = glob($logGlob);

        if (false === $logFiles || [] === $logFiles) {
            // No log files at all — fine, nothing to check.
            return;
        }

        $noHandlerPatterns = [
            'No handler for message',
            'NoHandlerForMessageException',
        ];

        foreach ($logFiles as $logFile) {
            $content = file_get_contents($logFile);
            if (false === $content) {
                continue;
            }

            foreach ($noHandlerPatterns as $pattern) {
                self::assertStringNotContainsString(
                    $pattern,
                    $content,
                    sprintf(
                        'Log file %s must not contain "%s" (hidden bus error from auto-compaction)',
                        basename($logFile),
                        $pattern,
                    ),
                );
            }
        }
    }


    public function testAutoCompactionLifecycleRowHasNoDuplicateEllipsis(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-auto-compact-ellipsis',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, 'Respond with a paragraph about AI agents.');
            $this->tmux->sendKey($pane, 'Enter');
            $cap = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation')
                    || str_contains($cap, 'Conversation compacted'),
                timeout: 20.0,
                message: 'Compaction lifecycle row not visible',
                history: 2000,
            );
            $this->assertStringNotContainsString('…...', $cap, 'Compaction progress must not show duplicate ellipsis');
        } finally {
            $this->tmux->killSession($pane);
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $dbPath = 'app_test-tui-auto-compact-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory following the exact pattern
     * of TuiCompactCommandE2eTest (including SafeGuard extension config).
     */
    private function createIsolatedProjectDir(): string
    {
        return $this->createIsolatedProjectDirWithSettings([
            'compaction' => [
                'auto_enabled' => true,
                'compact_after_tokens' => 10,
                'keep_recent_tokens' => 5,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $extraSettings merged into the base settings
     */
    private function createIsolatedProjectDirWithSettings(array $extraSettings): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-auto-compact');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        $settings = $this->buildBaseSettings($extraSettings);

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    /**
     * Build the base settings array, merging in extra keys.
     *
     * Matches TuiCompactCommandE2eTest pattern exactly (includes
     * SafeGuard extension config) so the agent can start correctly.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function buildBaseSettings(array $extra): array
    {
        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://192.168.2.38:9052/v1',
                        'api' => 'openai-completions',
                        'api_key' => 'dummy',
                        'completions_path' => '/chat/completions',
                        'supports_completions' => true,
                        'supports_embeddings' => false,
                        'supports_thinking_levels' => true,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'thinking_level_map' => [
                                    'off' => '0',
                                    'minimal' => '0',
                                    'low' => '0',
                                    'medium' => '0',
                                    'high' => '0',
                                    'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => [
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                ],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => [
                            'bash' => 'bash',
                            'write' => 'write',
                            'edit' => 'edit',
                            'read' => 'read',
                        ],
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b'],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        return array_merge_recursive($settings, $extra);
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
