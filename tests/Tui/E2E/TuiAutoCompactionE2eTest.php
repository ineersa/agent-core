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
            usleep(500_000);

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
            // either "Compacting conversation..." (progress, if a worker
            // picks up the async step) or "Compaction failed: ..."
            // (structural failure, e.g. too few messages on a tiny
            // session).  BOTH prove the auto trigger path is functional
            // end-to-end (hook → dispatch → handler → runtime events →
            // projection → visible TUI).
            //
            // The test uses a tiny 2-message session with
            // keep_recent_tokens=5, so compaction predictably fails with
            // "too few messages".  The key invariant is: compaction
            // became visible WITHOUT typing /compact.
            //
            // Wait for any compaction-related visible block.
            $autoCompactCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation')
                    || str_contains($cap, 'Compaction failed'),
                timeout: 20.0,
                message: 'Auto-compaction progress/failure not shown in TUI',
                history: 2000,
            );

            self::assertThat(
                $autoCompactCapture,
                self::logicalOr(
                    self::stringContains('Compacting conversation'),
                    self::stringContains('Compaction failed'),
                ),
                'Auto-compaction must produce visible compaction-related text in TUI without manual /compact',
            );

            // Post-compaction: wait for any retry mechanics to settle
            // so the log is complete before inspection.
            usleep(500_000);

            // ── Structural proof: exactly one auto-compaction lifecycle ──
            // Without the effectsCount > 0 guard in
            // AutoCompactionHookSubscriber, the hook fires on intermediate
            // orchestration commits (that have outbound effects), stacking
            // with the pre-LLM guard to produce duplicate
            // context_compaction_failed events (three identical failures
            // for one user prompt).  This assertion catches that regression
            // by reading the canonical events.jsonl directly.
            $this->assertExactlyOneAutoCompactionLifecycle();

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
     * Assert events.jsonl contains exactly one auto-compaction lifecycle
     * outcome (context_compaction_failed, context_compacted, or
     * context_compaction_started with trigger=auto).
     *
     * Without the effectsCount > 0 guard, intermediate-orchestration
     * commits trigger the hook multiple times per turn, producing
     * duplicate/failed events that the TUI-visible assertion alone
     * cannot distinguish (all blocks look the same).
     */
    private function assertExactlyOneAutoCompactionLifecycle(): void
    {
        $eventLog = $this->testProjectDir.'/.hatfield/sessions/1/events.jsonl';

        if (!\is_file($eventLog)) {
            self::fail(
                'events.jsonl not found at '.$eventLog
                .' — TUI session did not produce expected event log.',
            );
        }

        $lines = \file($eventLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        $autoCompactionEvents = [];

        $lifecycleTypes = [
            'context_compaction_started',
            'context_compacted',
            'context_compaction_failed',
        ];

        foreach ($lines as $line) {
            $event = \json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            if (!\in_array($type, $lifecycleTypes, true)) {
                continue;
            }

            $payload = $event['payload'] ?? [];
            $trigger = $payload['trigger'] ?? null;

            if ('auto' === $trigger) {
                $autoCompactionEvents[] = [
                    'seq' => $event['seq'] ?? 0,
                    'type' => $type,
                    'reason' => $payload['reason'] ?? 'n/a',
                ];
            }
        }

        $count = \count($autoCompactionEvents);
        self::assertEquals(
            1,
            $count,
            \sprintf(
                'Expected exactly 1 auto-compaction lifecycle event, found %d: %s',
                $count,
                \json_encode($autoCompactionEvents),
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
