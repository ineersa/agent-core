<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E proof: Cancelling status stickiness (issue #151 cosmetic flicker fix).
 *
 * Asserts that after the user presses Escape to cancel a run and the
 * TUI shows "Cancelling", the status does NOT flip back to "Working"
 * (which was caused by late mid-turn streaming deltas regressing the
 * ActivityStateMachine back to Running).
 *
 * Uses a replay-backed LLM fixture (no live LLM) and the real interactive
 * TUI via TmuxHarness. This is a hard gate per AGENTS.md — TUI feature
 * implementation is not complete without a TmuxHarness E2E proof.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class CancelStickinessE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Cancel a running turn and assert the status stays Cancelling
     * without regressing to Working.
     *
     * Strategy: submit a prompt that triggers a bash tool-call fixture
     * (sleep 1), wait for the tool-execution indicator (ToolResult
     * "Running…" block) so Escape is guaranteed to land during the
     * multi-second tool phase rather than the instant-replay LLM step,
     * then verify the footer never shows "Working" after "Cancelling".
     */
    public function testCancellingDoesNotRevertToWorking(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'cancel-stickiness',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup (20s under parallel castor check contention).
            $this->tmux->waitForTuiReady($pane);

            // Clear any residual editor state.
            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->sendKey($pane, 'C-u');

            // Send a short bash tool-call prompt (sleep 1). The tool phase
            // still gives Escape a deterministic landing window without the
            // instant-replay LLM step finishing first.
            $this->tmux->sendLiteral($pane, 'Run sleep 1');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the tool execution indicator: the ToolResult block
            // "Running…" appears in the transcript only after
            // tool_execution_started fires in the tool consumer.  During
            // instant-replay LLM steps this is the ONLY reliable boundary
            // between the LLM step (instant) and the tool execution phase.
            // Escape sent immediately after this appears lands during the
            // multi-second bash sleep, guaranteeing Cancelling renders.
            $this->tmux->waitForHistoryContains($pane, 'Running', 20.0);

            // Cancel the run — now guaranteed to land during tool execution,
            // not during the instant-replay LLM step.
            $this->tmux->sendKey($pane, 'Escape');

            // Wait for the Cancelling status to appear (footer shows "Cancelling...").
            $cancellingCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelling')
                    || str_contains($cap, 'cancelling'),
                timeout: 20.0,
                message: 'Cancelling status did not appear after Escape',
                history: 2000,
            );
            $this->assertTrue(
                str_contains($cancellingCapture, 'Cancelling')
                    || str_contains($cancellingCapture, 'cancelling'),
                'Cancelling must appear in capture — cancel did not render in the TUI',
            );

            // Poll until cancel settles: while Cancelling is visible, late mid-turn
            // deltas must not regress the footer to "◐ Working". A single snapshot
            // after a fixed sleep is racy — fast cancel completion removes
            // "Cancelling..." from the screen before we re-capture (ParaTest /
            // full castor check load), which falsely failed the old assertNotFalse.
            $settledCapture = $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    $cancellingPos = mb_strrpos($cap, 'Cancelling');
                    if (false === $cancellingPos) {
                        $cancellingPos = mb_strrpos($cap, 'cancelling');
                    }

                    // Cancel finished: terminal idle/cancelled states are success.
                    if (false === $cancellingPos) {
                        return str_contains($cap, 'Cancelled')
                            || str_contains($cap, '● idle');
                    }

                    $afterCancelling = mb_substr($cap, $cancellingPos);

                    return !str_contains($afterCancelling, '◐ Working');
                },
                timeout: 8.0,
                message: 'Footer must NOT show "Working" after "Cancelling" — late deltas must not regress the status',
                history: 2000,
            );

            $cancellingPos = mb_strrpos($settledCapture, 'Cancelling');
            if (false === $cancellingPos) {
                $cancellingPos = mb_strrpos($settledCapture, 'cancelling');
            }
            if (false !== $cancellingPos) {
                $this->assertStringNotContainsString(
                    '◐ Working',
                    mb_substr($settledCapture, $cancellingPos),
                    'Footer must NOT show "Working" after "Cancelling" — late deltas must not regress the status',
                );
            }
            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePaths = [];

        // Use the bash-sleep fixture: triggers a real bash sleep 1,
        // giving the cancel mechanism time to propagate and the TUI time
        // to render the Cancelling status.
        $toolCallFixture = __DIR__.'/fixtures/tui-tool-call-bash-sleep.json';
        if (is_file($toolCallFixture)) {
            $fixturePaths[] = $toolCallFixture;
        }

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $fixtureEnv = '' !== $fixturePaths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $fixturePaths)).' '
            : '';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-cancel-sticky-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-cancel');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();
        $settings['extensions']['settings']['safe_guard']['allow_command_patterns'] = ['^ls\b', '^printf\b', '^echo\b', '^sleep\b'];
        unset($settings['extensions']['settings']['safe_guard']['allow_write_outside_cwd']);

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        // Create the test file the tool-call fixture expects to read.
        file_put_contents($dir.'/home/test.txt', 'Hello from cancel-stickiness test');

        return $dir;
    }
}
