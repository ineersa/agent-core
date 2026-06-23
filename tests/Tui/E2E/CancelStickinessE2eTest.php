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
     * (sleep 15), wait for the tool-execution indicator (ToolResult
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
            // Wait for TUI startup.
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Clear any residual editor state.
            $this->tmux->sendKey($pane, 'Escape');
            usleep(100_000);
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);

            // Send a slow bash tool-call prompt (sleep 15). The 15-second
            // window guarantees Escape always lands during tool execution, not
            // the instant-replay LLM step. With `sleep 3` the tool could
            // complete before Escape, especially with replay fixtures where
            // LLM deltas are instantaneous.
            $this->tmux->sendLiteral($pane, 'Run sleep 15');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the tool execution indicator: the ToolResult block
            // "Running…" appears in the transcript only after
            // tool_execution_started fires in the tool consumer.  During
            // instant-replay LLM steps this is the ONLY reliable boundary
            // between the LLM step (instant) and the tool execution phase.
            // Escape sent immediately after this appears lands during the
            // multi-second bash sleep, guaranteeing Cancelling renders.
            $this->tmux->waitForHistoryContains($pane, 'Running', 15.0);

            // Cancel the run — now guaranteed to land during tool execution,
            // not during the instant-replay LLM step.
            $this->tmux->sendKey($pane, 'Escape');

            // Wait for the Cancelling status to appear.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Cancelling')
                    || str_contains($cap, 'cancelling'),
                timeout: 10.0,
                message: 'Cancelling status did not appear after Escape',
                history: 2000,
            );

            // Give the TUI a moment to process any late deltas that would
            // otherwise flip the status back to Working.
            usleep(500_000);

            // Now capture the full history and assert "Working" no longer
            // appears in the footer after the most recent "Cancelling".
            $capture = $this->tmux->capturePlainWithHistory($pane, 2000);

            // Find the position of the LAST "Cancelling" occurrence.
            $cancellingPos = mb_strrpos($capture, 'Cancelling');
            if (false === $cancellingPos) {
                // If using lowercase "cancelling"
                $cancellingPos = mb_strrpos($capture, 'cancelling');
            }

            // Assert Cancelling is present before checking that Working
            // does NOT follow it. If Cancelling is absent, the subsequent
            // guard would silently skip the assertion (0 assertions = risky).
            $this->assertNotFalse(
                $cancellingPos,
                'Cancelling must appear in capture — cancel did not render in the TUI',
            );

            $afterCancelling = mb_substr($capture, $cancellingPos);
            $this->assertStringNotContainsString(
                '◐ Working',
                $afterCancelling,
                'Footer must NOT show "Working" after "Cancelling" — late deltas must not regress the status',
            );
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

        // Use the bash-sleep fixture: triggers a real bash sleep 15,
        // giving the cancel mechanism several seconds to propagate and
        // the TUI time to render the Cancelling status.
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

        $dbPath = 'app_test-tui-cancel-sticky-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'2>&1',
            escapeshellarg($dbPath),
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
                                    'off' => '0', 'minimal' => '0', 'low' => '0',
                                    'medium' => '0', 'high' => '0', 'xhigh' => '0',
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
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b', '^sleep\b'],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        // Create the test file the tool-call fixture expects to read.
        file_put_contents($dir.'/home/test.txt', 'Hello from cancel-stickiness test');

        return $dir;
    }
}
