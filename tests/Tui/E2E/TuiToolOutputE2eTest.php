<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E test proving tool call result OUTPUT is visible in the TUI transcript,
 * not just a terse "{tool_name} completed" fallback.
 *
 * Uses a replay fixture that serves a read tool call.  The read tool
 * executes for real in the isolated project directory, reading ./test.txt
 * which contains a unique sentinel string.  After our fix for #131, the
 * actual file content must appear in the transcript instead of "read
 * completed".
 *
 * Design:
 *  - Single tmux session with a replay fixture that returns a read tool_call.
 *  - Isolated project dir has ./test.txt with sentinel content.
 *  - Submits a prompt; the fixture triggers a real read tool execution.
 *  - After tool execution, LLM fixture exhaustion fallback returns "done".
 *  - Asserts sentinel file content is visible in the TUI transcript.
 *  - Asserts "read completed" fallback is absent (output flows now).
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiToolOutputE2eTest extends TestCase
{
    /** Sentinel that the read tool should capture from ./test.txt. */
    private const OUTPUT_SENTINEL = 'TOOL_OUTPUT_SENTINEL_131_READ';
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
     * Submit a prompt that triggers a read tool call via replay fixture.
     *
     * Asserts in order:
     *  1. An assistant block (◇) appears post-tool-execution.
     *  2. The sentinel file content from the real read is visible in the
     *     transcript (proving tool result output is shown, not just
     *     "read completed").
     *  3. The "read completed" fallback label is absent.
     *  4. A session ID appears in the footer.
     */
    public function testToolResultShowsActualOutput(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-tool-output',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a prompt.  The replay fixture serves a read tool_call;
            // the read tool executes for real; then the LLM fixture exhaustion
            // fallback returns "done".
            $this->tmux->sendKey($pane, 'C-u');

            $prompt = 'Read ./test.txt';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant response block (◇) — signals
            // the tool executed and the LLM (fixture fallback) responded.
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 15.0,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after tool execution',
                history: 2000,
            );

            // The turn must complete with an assistant block (not error).
            $this->assertTrue(
                str_contains($capture, '◇'),
                'Transcript must display an assistant block (◇) after tool execution + done response',
            );

            // Capture full transcript history for assertions.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);

            // Tool call card should show YAML arguments from replay fixture.
            $this->assertStringContainsString(
                'path:',
                $fullCapture,
                'Tool call card must render YAML arguments (path key)',
            );
            $this->assertStringContainsString(
                './test.txt',
                $fullCapture,
                'Tool call card must render YAML argument value from read tool call',
            );
            $this->assertStringNotContainsString(
                '```yaml',
                $fullCapture,
                'Tool call card must not include fenced YAML markers',
            );

            // 1. The real tool output (file content) must appear in the transcript.
            $this->assertStringContainsString(
                self::OUTPUT_SENTINEL,
                $fullCapture,
                'Tool result must show actual file content, not just "read completed" fallback. '
                .'If this fails, the fix in ToolCallResultHandler did not propagate result text.',
            );

            // 2. The "read completed" fallback must NOT appear (real output flows now).
            // Tool name "read" may appear in the tool CALL block, but "read completed"
            // is the specific fallback label we want to prove absent.
            $this->assertStringNotContainsString(
                'read completed',
                $fullCapture,
                'Tool result fallback "read completed" must NOT appear when real output flows. '
                .'The result text replaces the fallback entirely.',
            );

            // 3. Verify session ID in footer.
            $this->assertStringContainsString(
                'session ',
                $fullCapture,
                'Session ID should appear in footer after prompt submission',
            );

            // 4. Save ANSI snapshot for inspection.
            $this->tmux->saveAnsiSnapshot($pane, 'tool-output');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'tool-output-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommandForFixture(string $fixtureFile): string
    {
        $fixturePath = __DIR__.'/fixtures/'.$fixtureFile;
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tool-edit-');

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

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-tool-call-read.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tool-output-');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-tool-output');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // Create a test file the read tool will read.  The sentinel is
        // what we assert appears in the TUI transcript.
        file_put_contents($dir.'/test.txt', self::OUTPUT_SENTINEL."\n");

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
