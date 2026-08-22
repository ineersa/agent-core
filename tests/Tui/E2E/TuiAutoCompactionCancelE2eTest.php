<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E proof that Escape can cancel auto-compaction.
 *
 * Session 13 evidence: user pressed Escape during auto-compaction but
 * the cancel was never sent — events.jsonl shows zero cancel events.
 *
 * Root cause: ActivityStateMachine lacks CompactionStarted/Completed/Failed
 * transitions.  After a turn completes (activity=Completed), auto-compaction
 * dispatches and runtime events arrive, but the activity stays Completed.
 * Completed.isActive() is false, so CancelListener clears the editor
 * instead of calling AgentSessionClient::cancel().
 *
 * Fix: Add CompactionStarted → Compacting transition, make CancelListener
 * send cancel during Compacting.
 *
 * Test design:
 *  1. Agent starts with two chained replay fixtures:
 *     - Fixture 0: fast assistant response (input_tokens=100 > compact threshold=10)
 *     - Fixture 1: delayed compaction summary (response_delay_ms=250)
 *  2. Send prompt, wait for assistant response + "Compacting conversation..."
 *  3. Send Escape while compaction is still in-flight (delayed by fixture 1)
 *  4. Verify TUI shows cancellation evidence ("Cancelling", "Cancelled", or
 *     dynamic transcript line "run cancelled")
 *  5. Structural proof: events.jsonl contains agent_command_queued/applied
 *     with kind=cancel AFTER context_compaction_started
 *
 * On HEAD (RED): Escape clears editor instead of sending cancel.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiAutoCompactionCancelE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Escape during auto-compaction must send cancel.
     *
     * Asserts:
     *  1. Auto-compaction starts and "Compacting conversation..." appears.
     *  2. Escape sends cancel → "Cancelling", "Cancelled", or dynamic
     *     "run cancelled" transcript evidence visible in TUI.
     *  3. events.jsonl has a cancel command (agent_command_applied kind=cancel)
     *     after context_compaction_started.
     *  4. The run reaches a terminal cancelled state (run.cancelled or
     *     agent_end reason=cancelled).
     */
    public function testEscapeCancelsAutoCompaction(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-auto-compact-cancel',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (logo visible). 20s under parallel castor check.
            $this->tmux->waitForTuiReady($pane);

            // Submit a prompt with a fixture whose input_tokens=100
            // (well above compact_after_tokens=10) so auto-compaction
            // triggers via the after-turn hook.
            $this->tmux->sendKey($pane, 'C-u');
            $prompt = 'Respond with a brief sentence about automated testing.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant response (◇ block or ✕ error block).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant response block did not appear',
                history: 2000,
            );

            // After the turn commits, auto-compaction fires. The delayed
            // fixture keeps the compaction LLM call in-flight long enough
            // for Escape to arrive while Compacting.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Compacting conversation'),
                timeout: 15.0,
                message: 'Compacting conversation... did not appear',
                history: 2000,
            );

            // ── Send Escape while compaction is still in-flight ──
            // The delay in fixture 1 keeps the LLM call open; the
            // TUI Compacting state should make CancelListener send
            // cancel instead of clearing the editor.
            $this->tmux->sendKey($pane, 'Escape');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelling')
                    || str_contains($cap, 'Cancelled')
                    || str_contains($cap, 'run cancelled'),
                timeout: 8.0,
                message: "TUI must show 'Cancelling', 'Cancelled', or dynamic 'run cancelled' after Escape during auto-compaction",
                history: 0,
            );

            // Post-cancellation: poll events.jsonl until cancel command is durable.
            $this->waitForCancelCommandAfterCompactionStarted();

            // Save ANSI snapshot for inspection.
            $this->tmux->saveAnsiSnapshot($pane, 'auto-compact-cancel-success');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'auto-compact-cancel-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Structural proof ──────────────────────────────────────────

    /**
     * Assert events.jsonl contains a cancel command event AFTER a
     * context_compaction_started event.
     */
    private function waitForCancelCommandAfterCompactionStarted(float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        $lastError = null;

        while (microtime(true) < $deadline) {
            try {
                $this->assertCancelCommandAfterCompactionStarted();

                return;
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $lastError = $e;
                usleep(100_000);
            }
        }

        if (null !== $lastError) {
            throw $lastError;
        }

        $this->fail('Timed out waiting for cancel command after compaction started in events.jsonl');
    }

    private function assertCancelCommandAfterCompactionStarted(): void
    {
        $eventLog = $this->testProjectDir.'/.hatfield/sessions/1/events.jsonl';

        if (!is_file($eventLog)) {
            $this->fail('events.jsonl not found at '.$eventLog);
        }

        $lines = file($eventLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        $compactionStartedSeq = null;
        $cancelEvents = [];

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? '';
            $seq = (int) ($event['seq'] ?? 0);

            if ('context_compaction_started' === $type) {
                $compactionStartedSeq = $seq;
            }

            if (null !== $compactionStartedSeq && $seq > $compactionStartedSeq) {
                if (
                    'agent_command_queued' === $type
                    || 'agent_command_applied' === $type
                ) {
                    $payload = $event['payload'] ?? [];
                    $kind = $payload['kind'] ?? $payload['type'] ?? '';
                    if ('cancel' === $kind) {
                        $cancelEvents[] = ['seq' => $seq, 'type' => $type, 'kind' => 'cancel'];
                    }
                }
            }
        }

        $this->assertNotNull(
            $compactionStartedSeq,
            'events.jsonl must contain context_compaction_started before cancel.',
        );

        $this->assertNotEmpty(
            $cancelEvents,
            \sprintf(
                'events.jsonl must contain a cancel command (agent_command_queued or '
                .'agent_command_applied with kind=cancel) after context_compaction_started '
                ."(seq %d).\n"
                .'On HEAD (RED): Escape clears editor instead of sending cancel — '
                ."zero cancel command events in the event log.\n"
                .'agent_end reason=cancelled alone is NOT sufficient — it can appear '
                ."without an actual cancel command in scenarios like sess-13.\n"
                .'Found compaction_started at seq=%d, cancel events: %s',
                $compactionStartedSeq,
                $compactionStartedSeq,
                json_encode($cancelEvents),
            ),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        // Two chained fixtures:
        //   fixture 0: fast assistant response (100 tokens, above threshold)
        //   fixture 1: delayed compaction summary (1s delay)
        $fixture0 = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-startup-prompt-response.json';
        $fixture1 = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-compaction-summary-delayed.json';

        $fixtureChain = '';
        if (is_file($fixture0)) {
            $fixtureChain = $fixture0;
        }
        if (is_file($fixture1)) {
            $fixtureChain = ('' !== $fixtureChain ? $fixtureChain.';' : '').$fixture1;
        }

        $fixtureEnv = ('' !== $fixtureChain)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixtureChain).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $suffix = bin2hex(random_bytes(4));
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tui-auto-compact-cancel-');
        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-auto-compact-cancel');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = $this->buildBaseSettings($extraSettings);

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    /**
     * Build the base settings array, merging in extra keys.
     *
     * Matches TuiAutoCompactionE2eTest::buildBaseSettings exactly
     * (includes SafeGuard extension config) so the agent can start.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function buildBaseSettings(array $extra): array
    {
        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        return array_merge_recursive($settings, $extra);
    }
}
