<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E test proving provider HTTP errors appear as sanitized red error blocks
 * in the TUI, with no raw provider body or prompting content leaked.
 *
 * Uses a replay fixture that returns a 429 HTTP error JSON body. The TUI
 * must display an error block (✕) with sanitized text like "LLM provider
 * rate limit" / "retryable" and must NOT display the raw sentinel string
 * from the fixture body.
 *
 * Design:
 *  - Single tmux session with a replay fixture that returns HTTP 429.
 *  - Submits a prompt, waits for either ◇ (assistant) or ✕ (error) block.
 *  - Asserts error block and sanitized text, asserts sentinel absent.
 *  - Captures ANSI snapshot on success/failure.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiProviderErrorE2eTest extends TestCase
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
     * Submit a prompt against the provider error replay fixture.
     *
     * Asserts in order:
     *  1. An error block (✕) appears in the transcript.
     *  2. Sanitized user-facing text is visible (e.g. "LLM provider rate limit").
     *  3. The raw sentinel body text from the fixture is NOT visible.
     *  4. Safe structured fields (retryable, error_category) are present.
     */
    public function testProviderRateLimitErrorShowsSanitizedRedBlock(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-provider-error',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // Submit a simple prompt that will trigger the LLM call.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);

            $prompt = 'Respond with exactly one sentence: the sky is blue.';
            $this->tmux->sendLiteral($pane, $prompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for either error block (✕) or assistant block (◇).
            // The fixture returns 429, so we expect an error.
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '✕')
                    || str_contains($cap, '◇'),
                timeout: 10.0,
                message: 'Neither ✕ error block nor ◇ assistant block appeared after prompt submission',
                history: 2000,
            );

            // 1. Must show an error block, not an assistant block.
            self::assertTrue(
                str_contains($capture, '✕'),
                'Transcript must display ✕ error block for provider error fixture',
            );
            self::assertStringNotContainsString(
                '◇',
                $capture,
                'Transcript must NOT show assistant block for provider error fixture',
            );

            // 2. Sanitized user-facing text must be visible.
            // The classifier produces "LLM provider rate limit" for 429.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::assertStringContainsString(
                'rate limit',
                strtolower($fullCapture),
                'Sanitized rate limit message must be visible in transcript',
            );
            self::assertStringContainsString(
                'retryable',
                strtolower($fullCapture),
                'Sanitized retryable indicator must be visible in transcript',
            );

            // 3. Raw sentinel body text must NOT be visible.
            self::assertStringNotContainsString(
                'DO_NOT_LEAK_PROVIDER_BODY',
                $fullCapture,
                'Raw provider body sentinel must NOT be leaked in TUI',
            );

            // 4. Save ANSI snapshot for inspection.
            $this->saveAnsiSnapshot($pane, 'provider-rate-limit-error');

            // Optionally check that the session metadata shows the error.
            $sessionCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::assertStringContainsString(
                'session ',
                $sessionCapture,
                'Session ID should appear in footer after prompt submission',
            );

            // Send clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'provider-error-FAILURE');
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
        $fixturePath = __DIR__.'/fixtures/tui-provider-rate-limit-error.json';
        $fixtureEnv = \is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $dbPath = 'app_test-tui-provider-error-'.bin2hex(random_bytes(4)).'.sqlite';

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

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-provider-error');
        @\mkdir($dir.'/.hatfield', 0o777, true);

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

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }

    private function savePlainSnapshot(TmuxPane $pane, string $tag): void
    {
        $plain = $this->tmux->capturePlainWithHistory($pane, 2000);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.txt', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $plain);
    }
}
