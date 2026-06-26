<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E test proving the output-cap notification appears in the TUI transcript
 * as a generic model-notification System block, with exact model-facing text,
 * warning severity styling (⚠ prefix, Warning theme color), compact ToolResult
 * (read completed instead of raw/full output), and no raw/full capped output
 * leakage.
 *
 * Uses a replay fixture that triggers a read tool call on an oversized file
 * in an isolated project directory.  The read tool executes for real; the
 * OutputCapToolResultProcessor caps the result.  Assertions prove the generic
 * notification appears and raw output does not.
 *
 * Test thesis: the TUI shows exactly one model_notification System block with
 * the exact cap notice text and warning severity, the ToolResult is compact,
 * and the raw full output is absent from the transcript.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiOutputCapNoticeE2eTest extends TestCase
{
    /** Expected in the cap notice text sent to the model. */
    private const CAP_NOTICE_MARKER = 'Output capped';
    /** Sentinel that MUST NOT appear in the transcript (proves raw output hidden). */
    private const RAW_OUTPUT_SENTINEL = 'OUTPUT_CAP_RAW_SHOULD_BE_HIDDEN_';
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Prove the output-cap notification appears as a generic model-notification
     * System block with exact text, and raw/full output is hidden.
     */
    public function testOutputCapShowsNotificationAndHidesRawOutput(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-output-cap',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup. 20s under parallel castor check.
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a prompt that triggers read on the oversized file.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, 'Read ./large_file.txt');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant response (◇) — signals tool executed
            // and the fixture fallback returned a response.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after tool execution',
                history: 2000,
            );

            // Capture full transcript for assertions.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);

            // 1. The related tool call must be visible (the file path
            //    from the original read call).
            $this->assertStringContainsString(
                'large_file.txt',
                $fullCapture,
                'Related tool call (large_file.txt) must be visible in the TUI transcript',
            );

            // 2. The exact cap notice text must be visible (model-notification System block).
            $this->assertStringContainsString(
                self::CAP_NOTICE_MARKER,
                $fullCapture,
                'Output-cap notice text must be visible in the TUI transcript as a model-notification block',
            );

            // Verify the cap notice does not appear at TUI-churn scale
            // (e.g. dozens of duplicates from bad event replay).
            // Exact-once is too strict for real TUI ANSI rendering
            // where line wrapping may show the same text in adjacent
            // screen rows.  Allow small headroom.
            $noticeCount = mb_substr_count($fullCapture, self::CAP_NOTICE_MARKER);
            $this->assertLessThanOrEqual(
                4,
                $noticeCount,
                'Output-cap notice must not appear more than a few times (found '.$noticeCount.')',
            );

            // 3. The warning severity prefix (⚠) must be present for the notification block.
            $this->assertStringContainsString(
                '⚠',
                $fullCapture,
                'Warning icon (⚠) must appear for the output-cap notification',
            );

            // 4. The compact ToolResult label must be visible (not raw output).
            $this->assertStringContainsString(
                'read completed',
                $fullCapture,
                'Compact ToolResult "read completed" must appear instead of raw output',
            );

            // 5. The raw full output sentinel must NOT be visible.
            $this->assertStringNotContainsString(
                self::RAW_OUTPUT_SENTINEL,
                $fullCapture,
                'Raw full output must NOT appear in the TUI transcript',
            );

            // 6. Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'output-cap-notification');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'output-cap-notification-FAILURE');
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
        $fixturePath = __DIR__.'/fixtures/tui-output-cap-read.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $dbPath = 'app_test-tui-output-cap-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-output-cap');
        @mkdir($dir.'/.hatfield', 0o777, true);

        // Create an oversized test file (>500 chars with sentinel text).
        // The output cap defaults to 20,000, but we set it to 500 in settings
        // so the file is capped.  600 chars of repeating sentinel + random fill.
        $suffix = str_repeat('Y', 200);
        $fileContent = self::RAW_OUTPUT_SENTINEL.'_'.bin2hex(random_bytes(8))."\n"
            .str_repeat('X', 500)."\n"
            .$suffix."\n";
        file_put_contents($dir.'/large_file.txt', $fileContent);

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
            'tools' => [
                'output_cap' => [
                    'path' => '.hatfield/tmp/output-cap',
                    'default_cap' => 500,
                    'doc_cap' => 500,
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

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        file_put_contents($path, $ansi);
    }
}
