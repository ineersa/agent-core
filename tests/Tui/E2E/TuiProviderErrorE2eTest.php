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
            $this->tmux->waitForTuiReady($pane);

            // Submit a simple prompt that will trigger the LLM call.
            $this->tmux->sendKey($pane, 'C-u');

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
            $this->assertTrue(
                str_contains($capture, '✕'),
                'Transcript must display ✕ error block for provider error fixture',
            );
            $this->assertStringNotContainsString(
                '◇',
                $capture,
                'Transcript must NOT show assistant block for provider error fixture',
            );

            // 2. Sanitized user-facing text must be visible.
            // The classifier produces "LLM provider rate limit" for 429.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertStringContainsString(
                'rate limit',
                strtolower($fullCapture),
                'Sanitized rate limit message must be visible in transcript',
            );
            $this->assertStringContainsString(
                'retryable',
                strtolower($fullCapture),
                'Sanitized retryable indicator must be visible in transcript',
            );

            // 3. Raw sentinel body text must NOT be visible.
            $this->assertStringNotContainsString(
                'DO_NOT_LEAK_PROVIDER_BODY',
                $fullCapture,
                'Raw provider body sentinel must NOT be leaked in TUI',
            );

            // 4. Save ANSI snapshot for inspection.
            $this->tmux->saveAnsiSnapshot($pane, 'provider-rate-limit-error');

            // Optionally check that the session metadata shows the error.
            $sessionCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertStringContainsString(
                'session ',
                $sessionCapture,
                'Session ID should appear in footer after prompt submission',
            );

            // Send clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'provider-error-FAILURE');
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
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-provider-error-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixWithLowLatencyMessenger($dbPath, $transportDbPath, $this->testProjectDir),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-provider-error');
        @mkdir($dir.'/.hatfield', 0o777, true);

        $settings = TuiE2eDatabaseEnv::replayBaseSettings();

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    private function savePlainSnapshot(TmuxPane $pane, string $tag): void
    {
        $plain = $this->tmux->capturePlainWithHistory($pane, 2000);
        $ts = date('Ymd-His');
        $path = \sprintf(
            '%s/.hatfield/tmp/tui/smoke/%s-%s.txt',
            $this->testProjectDir,
            $tag,
            $ts,
        );
        @mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, $plain);
    }
}
