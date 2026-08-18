<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux proof that the edit tool-exchange card renders its
 * compacted result body on the live path.
 *
 * Test thesis: after the result-body facts moved from the pairing policy into
 * {@see \Ineersa\Tui\Transcript\TranscriptToolResultFacts}, a real terminal
 * session still renders the edit tool-exchange card with the success stats
 * visible AND the compacted body does NOT leak the 'Updated file context:'
 * marker that follows it in the raw tool result (EditFileTool appends it).
 *
 * The edit tool executes for real in the isolated project dir against
 * ./target.txt (patch: before → after); the replay fixture only supplies the
 * tool_call. The fixture then exhausts and the replay fallback returns "done".
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiToolExchangeCardE2eTest extends TestCase
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

    public function testEditToolExchangeCardShowsCompactedBodyWithoutFileContextLeak(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-tool-exchange-card',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a prompt. The replay fixture serves an edit tool_call;
            // the edit tool executes for real; then the LLM fixture exhaustion
            // fallback returns "done".
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'Edit target.txt');
            $this->tmux->sendKey($pane, 'Enter');

            // The edit exchange card must appear (either its diff preview or the compacted result body).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '-before')
                    || str_contains($cap, 'Applied patch'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Edit tool exchange card never appeared in transcript',
                history: 3000,
            );

            // Turn completes with an assistant block (fixture exhaustion "done").
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after edit tool execution',
                history: 2000,
            );

            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 3000);
            // Soft-wrap can split phrases across lines when the absolute checkout path
            // is long (temp dirs live under <checkout>/var/tmp/). Collapse whitespace
            // so phrase assertions are path-length-independent.
            $normalized = preg_replace('/\s+/', ' ', $fullCapture) ?? $fullCapture;

            // Exchange card header/args from the tool_call half.
            $this->assertStringContainsString(
                'path:',
                $normalized,
                'Tool exchange card must render YAML arguments (path key)',
            );
            $this->assertStringContainsString(
                'target.txt',
                $normalized,
                'Tool exchange card must render the edit target path',
            );

            // Compacted result body (EditFileTool success stats) must be visible.
            $this->assertStringContainsString(
                '1 addition, 1 deletion',
                $normalized,
                'Tool exchange card must render the compacted edit success body',
            );

            // The raw result's trailing file context must NOT leak into the card.
            $this->assertStringNotContainsString(
                'Updated file context:',
                $normalized,
                'Compacted edit result body must not leak the "Updated file context:" marker '
                .'(result-body facts moved to TranscriptToolResultFacts)',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'tool-exchange-card');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'tool-exchange-card-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-tool-call-edit.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tool-exchange-');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-tool-exchange');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // The edit tool applies the fixture's patch (before → after) for real.
        file_put_contents($dir.'/target.txt', "before\n");

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
