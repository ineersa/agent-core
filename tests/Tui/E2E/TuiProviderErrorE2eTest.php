<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Terminal smoke proving provider error details survive retry exhaustion.
 * The HTTP 429 fixture's error-message sentinel must remain visible.
 * Bounding, redaction, and control removal are proven in classifier tests.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiProviderErrorE2eTest extends TestCase
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
     *  3. The provider error-message sentinel remains visible.
     *  4. The terminal error does not promise another retry.
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

            // 2. Sanitized terminal text must explain that application retries were exhausted.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertStringContainsString(
                'rate limit',
                strtolower($fullCapture),
                'Sanitized rate limit message must be visible in transcript',
            );
            $this->assertStringContainsString(
                'after retries were exhausted',
                strtolower($fullCapture),
                'Terminal retry exhaustion must be visible in transcript',
            );
            $this->assertStringNotContainsString(
                'retryable',
                strtolower($fullCapture),
                'Terminal exhaustion must not promise another retry',
            );

            // 3. Preserve the provider's actual error message.
            $this->assertStringContainsString(
                'DO_NOT_LEAK_PROVIDER_BODY',
                $fullCapture,
                'Provider error detail must remain visible in TUI',
            );

            // 4. Save ANSI snapshot for inspection.
            $this->tmux->saveAnsiSnapshot($pane, 'provider-rate-limit-error');

            // Send clean exit.
            $this->tmux->sendKey($pane, 'C-d');
            $this->tmux->waitUntilPaneExits($pane);
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
        // This journey proves terminal error presentation, not HTTP backoff.
        $settings['ai']['http']['max_retries'] = 0;

        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
