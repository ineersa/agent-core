<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test: start agent TUI, type a prompt, submit,
 * wait for assistant/error response, capture ANSI snapshot, and
 * verify the transcript contains real projected TranscriptBlocks.
 *
 * Uses the real project configuration (dev env) so the configured
 * LLM providers are active.  If no provider is configured or the
 * provider fails, the test still asserts a user-visible error
 * block appears (instead of a stuck "Processing..." indicator).
 *
 * On failure, dumps the ANSI snapshot and session files to stdout
 * for debugging.
 *
 * @group tui-e2e
 * @group llm-real
 */
#[Group('tui-e2e')]
#[Group('llm-real')]
final class TuiAgentSmokeTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $snapshotDir;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = \realpath(__DIR__ . '/../../..');
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testProjectDir)) {
            $this->removeDir($this->testProjectDir);
        }
    }

    /**
     * Full TUI smoke test with real LLM (if configured).
     *
     * 1) Starts agent TUI in tmux with fixed dimensions
     * 2) Types a prompt
     * 3) Submits via Enter
     * 4) Waits for assistant block (◇) or error block (✕) to appear
     * 5) Asserts transcript contains both the user message and
     *    either an assistant or error block — proving the full
     *    Messenger → EventStore → Mapper → Projector → TUI pipeline
     * 6) Captures ANSI snapshot artifact
     */
    public function testTypePromptAndVerifyTranscriptBlocks(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-agent-smoke',
            cwd: $this->testProjectDir,
        );

        try {
            // Step 1: Wait for TUI to render
            $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: '█',   // Hatfield logo
                timeout: 10.0,
            );
            \usleep(500_000); // extra settle

            // Step 2: Type a prompt
            $prompt = 'Respond with exactly one word: hello.';
            $this->tmux->sendLiteral($pane, $prompt);
            \usleep(200_000);

            // Step 3: Submit
            $this->tmux->sendKey($pane, 'Enter');
            \usleep(500_000);

            // Step 4: Wait for response — an assistant block (◇) or
            // an explicit error block (✕).  "Working..." / "Processing..."
            // going away is NOT sufficient; we need actual block output.
            try {
                $capture = $this->tmux->waitForCaptureContains(
                    pane: $pane,
                    needle: '◇',    // TranscriptBlockKind::AssistantMessage prefix
                    timeout: 180.0,
                );
            } catch (\RuntimeException $e) {
                // Maybe the LLM failed — look for an error block instead.
                try {
                    $capture = $this->tmux->waitForCaptureContains(
                        pane: $pane,
                        needle: '✕',    // TranscriptBlockKind::Cancelled/Error prefix
                        timeout: 5.0,
                    );
                } catch (\RuntimeException $inner) {
                    // Neither appeared. Dump diagnostics and fail.
                    $this->dumpArtifacts(
                        $pane,
                        'Neither ◇ assistant block nor ✕ error block appeared '
                            . 'after prompt submission.',
                    );
                    self::fail(
                        'Transcript did not display an assistant or error block. '
                            . 'See snapshot above for the terminal state.',
                    );
                }
            }

            // Step 5: Assert expected transcript structure.
            // The user message (❯) must be present.
            self::assertStringContainsString(
                '❯',
                $capture,
                'Transcript must include user message block (❯ prefix). '
                    . 'The user prompt should be visible after submission.',
            );

            self::assertStringContainsString(
                $prompt,
                $capture,
                'Transcript must include the typed prompt text.',
            );

            // Processing... placeholder MIGHT be gone by now (first runtime
            // event triggers its removal).  But allow a brief grace period.
            // We don't assert absence of "Processing..." because on very slow
            // models the block removal race condition could fail spuriously.
            // Instead, assert the transcript has at least 2 blocks:
            // user block (❯) + either assistant (◇) or error (✕).
            $hasAssistant = \str_contains($capture, '◇');
            $hasError = \str_contains($capture, '✕');
            self::assertTrue(
                $hasAssistant || $hasError,
                'Transcript must display either an assistant block (◇) '
                    . 'or an error block (✕) after prompt submission. '
                    . \sprintf(
                        'Current capture (%d lines):%s%s',
                        \substr_count($capture, "\n") + 1,
                        "\n",
                        $capture,
                    ),
            );

            // If the assistant block appeared, verify it's not empty.
            if (\str_contains($capture, '◇')) {
                // The assistant prefix should be followed by content.
                $prefixPos = \strpos($capture, '◇');
                $afterPrefix = \substr($capture, $prefixPos + \strlen('◇ '));
                $firstLineAfter = \explode("\n", $afterPrefix, 2)[0] ?? '';
                self::assertNotEmpty(
                    \trim($firstLineAfter),
                    'Assistant block should contain response text after the ◇ prefix.',
                );
            }

            // Step 6: Save ANSI snapshot artifact.
            $this->saveAnsiSnapshot($pane, 'agent-flow-smoke');

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
            \usleep(300_000);
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            // Always try to save an artifact, even on failure.
            try {
                $this->saveAnsiSnapshot($pane, 'agent-flow-smoke-FAILURE');
            } catch (\Throwable) {
                // ignore secondary failures
            }

            // Try to clean up — but don't hide the original error.
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    /**
     * Verify that after a prompt submission, the TUI does not get
     * permanently stuck on "Processing..." / "Working...".
     *
     * This guards against the Messenger bus silently dropping messages
     * (empty middleware) where the TUI never transitions away from
     * the working state.
     */
    public function testWorkingStatusTransitionsAfterSubmit(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-agent-status',
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            \usleep(500_000);

            // Send prompt
            $this->tmux->sendLiteral($pane, 'hello');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for either an assistant block or error block.
            // This proves the working status didn't stay stuck.
            try {
                $this->tmux->waitForCaptureContains($pane, '◇', 180.0);
            } catch (\RuntimeException) {
                $this->tmux->waitForCaptureContains($pane, '✕', 10.0);
            }

            \usleep(300_000);
            $capture = $this->tmux->capturePlain($pane);

            // The "Working..." indicator should NOT be stuck.
            // After the LLM pipeline completes (or errors), the
            // TUI should transition away from the working state.
            // At minimum, we should see either some block output
            // or the idle indicator returning.
            self::assertTrue(
                \str_contains($capture, '◇') || \str_contains($capture, '✕'),
                'TUI must show assistant or error block — '
                    . '"Working..." status cannot be stuck forever.',
            );

            $this->tmux->sendKey($pane, 'C-d');
            \usleep(300_000);
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    /**
     * Multi-turn smoke test: type two prompts in one session and
     * verify both receive visible assistant responses in correct
     * conversation order.
     *
     * This catches:
     *  - Only-first-message-works bugs (second prompt silently dropped)
     *  - Blocks out of order (thinking after message, duplicate blocks)
     *  - Empty/placeholder thinking blocks
     *  - Processing/Working shown together
     */
    public function testMultiTurnConversationOrder(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-multiturn',
            cwd: $this->testProjectDir,
        );

        try {
            // ── Start first turn ──
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            \usleep(500_000);

            $prompt1 = 'Say exactly: one';
            $this->tmux->sendLiteral($pane, $prompt1);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for first assistant response
            try {
                $this->tmux->waitForCaptureContains($pane, '◇', 180.0);
            } catch (\RuntimeException) {
                $this->tmux->waitForCaptureContains($pane, '✕', 10.0);
            }
            \usleep(500_000);

            $firstCapture = $this->tmux->capturePlain($pane);

            // Verify first response has correct structure
            self::assertStringContainsString('❯', $firstCapture, 'First user block should be visible');
            self::assertStringContainsString('◇', $firstCapture, 'First assistant response should be visible');

            // No empty thinking placeholders: if ⋯ thinking is visible,
            // it should have actual text, not just "[thinking]".
            if (\str_contains($firstCapture, '⋯')) {
                $thinkingText = $this->extractBlockText($firstCapture, '⋯');
                self::assertNotEmpty(
                    \trim(\str_replace('[thinking]', '', $thinkingText)),
                    'Thinking block must not be empty placeholder — '
                        . \sprintf('got: "%s"', $thinkingText),
                );
            }

            // ── Start second turn ──
            // Type a follow-up prompt
            $prompt2 = 'Say exactly: two';

            // Capture the current ◇ count BEFORE the second submit so we
            // can detect new assistant blocks (avoid counting stale first-turn ◇).
            $beforeCount = \substr_count($this->tmux->capturePlain($pane), "◇");

            $this->tmux->sendLiteral($pane, $prompt2);
            $this->tmux->sendKey($pane, 'Enter');

            // Poll until ◇ count increases (proves a new assistant block
            // appeared from the second LLM turn) or error block appears.
            $secondCapture = '';
            $deadline = \microtime(true) + 180.0;
            do {
                $currentCapture = $this->tmux->capturePlain($pane);
                $currentAssistantCount = \substr_count($currentCapture, "◇");
                if ($currentAssistantCount > $beforeCount || \str_contains($currentCapture, "✕")) {
                    $secondCapture = $currentCapture;
                    break;
                }
                \usleep(250_000);
            } while (\microtime(true) < $deadline);

            if ('' === $secondCapture) {
                // ◇ count never increased. Dump diagnostics and fail.
                $secondCapture = $this->tmux->capturePlain($pane);
                $this->dumpArtifacts(
                    $pane,
                    'Second assistant block (◇) count did not increase after '
                        . 'second prompt submission. '
                        . \sprintf(
                            'Before: %d, After (timeout): %d, Error visible: %s.',
                            $beforeCount,
                            \substr_count($secondCapture, "◇"),
                            \str_contains($secondCapture, "✕") ? 'yes' : 'no',
                        ),
                );
                self::fail(
                    'Second prompt did not produce a new assistant or error block. '
                        . 'See snapshot above for the terminal state.',
                );
            }

            $this->saveAnsiSnapshot($pane, 'multiturn-final');

            // ── Assertions on final state ──

            // Both user prompts visible
            self::assertStringContainsString(
                $prompt1,
                $secondCapture,
                'First prompt must be visible in transcript',
            );
            self::assertStringContainsString(
                $prompt2,
                $secondCapture,
                'Second prompt must be visible in transcript',
            );

            // At least two ❯ user blocks visible
            $userCount = \substr_count($secondCapture, '❯');
            self::assertGreaterThanOrEqual(
                2,
                $userCount,
                \sprintf(
                    'Expected at least 2 user blocks, found %d. '
                        . 'Second prompt may have been silently dropped.',
                    $userCount,
                ),
            );

            // At least two assistant responses
            $assistantCount = \substr_count($secondCapture, '◇');
            self::assertGreaterThanOrEqual(
                2,
                $assistantCount,
                \sprintf(
                    'Expected at least 2 assistant blocks, found %d. '
                        . 'Second LLM invocation may have silently failed.',
                    $assistantCount,
                ),
            );

            // Verify conversation order: first user → first assistant
            // → second user → second assistant
            $firstUserPos = \strpos($secondCapture, $prompt1);
            $secondUserPos = \strpos($secondCapture, $prompt2);
            self::assertLessThan(
                $secondUserPos,
                $firstUserPos,
                'First user message must appear before second user message',
            );

            // No "Processing..." block should be visible in final settled state
            self::assertStringNotContainsString(
                'Processing...',
                $secondCapture,
                '"Processing..." block must be gone in settled state '
                    . '(it should be removed on first runtime event)',
            );

            // No stuck "Working..." with no assistant — prove we got real responses
            self::assertStringContainsString(
                '◇',
                $secondCapture,
                'At least one assistant block must be visible',
            );

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
            \usleep(300_000);
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try {
                $this->saveAnsiSnapshot($pane, 'multiturn-FAILURE');
            } catch (\Throwable) {
            }
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    /**
     * Extract the text content of a block with the given prefix
     * from the plain-text capture.
     */
    private function extractBlockText(string $capture, string $prefix): string
    {
        $pos = \strpos($capture, $prefix);
        if (false === $pos) {
            return '';
        }
        $after = \substr($capture, $pos + \strlen($prefix) + 1);
        $newline = \strpos($after, "\n");
        if (false === $newline) {
            return \trim($after);
        }

        return \trim(\substr($after, 0, $newline));
    }

    // ── helpers ────────────────────────────────────────────

    private function agentCommand(): string
    {
        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test --reasoning=off 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf('%s/var/tmp/tui-e2e-%s', $this->projectRoot, \bin2hex(\random_bytes(6)));
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        $projectSettings = $this->projectRoot.'/.hatfield/settings.yaml';
        if (\is_readable($projectSettings)) {
            $settings = (string) \file_get_contents($projectSettings);
            $settings = \preg_replace(
                '/^ai:\n/m',
                "ai:\n    default_model: llama_cpp_test/test\n    default_reasoning: off\n",
                $settings,
                1,
            ) ?? $settings;
            \file_put_contents($dir.'/.hatfield/settings.yaml', $settings);
            \file_put_contents($dir.'/home/.hatfield/settings.yaml', $settings);
        }

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = \date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }

    /**
     * Collect diagnostic artifacts: ANSI snapshot, logs, messenger DB info,
     * and session files from the isolated test CWD.
     */
    private function dumpArtifacts(TmuxPane $pane, string $context): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $plain = $this->tmux->capturePlain($pane);
        $paneExists = $this->tmux->paneExists($pane) ? 'yes' : 'no';

        \fwrite(\STDERR, "\n\n=== TUI SMOKE FAILURE ===\n");
        \fwrite(\STDERR, "Context: {$context}\n");
        \fwrite(\STDERR, "Pane exists: {$paneExists}\n");
        \fwrite(\STDERR, "Test CWD: {$this->testProjectDir}\n\n");

        $ts = \date('Ymd-His');
        $dumpDir = $this->projectRoot . '/var/tmp/tui-failures';
        @\mkdir($dumpDir, 0o777, true);

        \file_put_contents("{$dumpDir}/fail-{$ts}.ansi", $ansi);
        \file_put_contents("{$dumpDir}/fail-{$ts}.txt", $plain);

        \fwrite(\STDERR, "Plain snapshot:\n{$plain}\n\n");

        $messengerDb = $this->testProjectDir.'/.hatfield/messenger.sqlite';
        \fwrite(\STDERR, \sprintf(
            "Messenger DB: %s (%s)\n\n",
            $messengerDb,
            \is_file($messengerDb) ? \filesize($messengerDb).' bytes' : 'missing',
        ));

        $this->dumpLogFiles($this->testProjectDir.'/.hatfield/logs');
        $this->dumpSessionFiles($this->testProjectDir.'/.hatfield/sessions');

        \fwrite(\STDERR, "=== END TUI SMOKE FAILURE ===\n\n");
    }

    private function dumpLogFiles(string $logsDir): void
    {
        \fwrite(\STDERR, "Logs dir: {$logsDir}\n");
        if (!\is_dir($logsDir)) {
            \fwrite(\STDERR, "--- logs missing ---\n\n");

            return;
        }

        foreach (\glob($logsDir.'/*.log') ?: [] as $logFile) {
            $content = (string) \file_get_contents($logFile);
            $tail = \implode("\n", \array_slice(\explode("\n", $content), -80));
            \fwrite(\STDERR, "--- log {$logFile} tail ---\n{$tail}\n\n");
        }
    }

    private function dumpSessionFiles(string $sessionsDir): void
    {
        \fwrite(\STDERR, "Sessions dir: {$sessionsDir}\n");
        if (!\is_dir($sessionsDir)) {
            \fwrite(\STDERR, "--- sessions missing ---\n\n");

            return;
        }

        $dirs = \glob($sessionsDir . '/*', \GLOB_ONLYDIR) ?: [];
        \usort($dirs, static fn (string $a, string $b): int => \filemtime($b) <=> \filemtime($a));

        foreach (\array_slice($dirs, 0, 3) as $sessionDir) {
            \fwrite(\STDERR, "Session: {$sessionDir}\n\n");
            foreach (['events.jsonl', 'transcript.jsonl', 'state.json', 'metadata.yaml', 'idempotency.jsonl'] as $file) {
                $path = $sessionDir . '/' . $file;
                if (\file_exists($path)) {
                    $content = (string) \file_get_contents($path);
                    $size = \strlen($content);
                    \fwrite(\STDERR, "--- {$file} ({$size} bytes) ---\n{$content}\n\n");
                } else {
                    \fwrite(\STDERR, "--- {$file} (missing) ---\n\n");
                }
            }
        }
    }

    private function removeDir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                \rmdir($file->getPathname());
            } else {
                @\chmod($file->getPathname(), 0o644);
                \unlink($file->getPathname());
            }
        }

        \rmdir($dir);
    }
}
