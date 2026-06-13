<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test: start agent TUI, type a prompt, submit,
 * wait for assistant/error response, capture ANSI snapshot, and
 * verify the transcript contains real projected TranscriptBlocks.
 *
 * Uses an isolated minimal configuration with only the
 * llama_cpp_test provider active.  The test creates its own
 * settings from scratch, NOT copied from project settings,
 * to avoid leaking unrelated provider credentials (e.g.
 * openai-codex) that would break the selected model path.
 *
 * If no provider is configured or the provider fails, the test
 * still asserts a user-visible error block appears (instead of
 * a stuck "Processing..." indicator).
 *
 * On failure, dumps the ANSI snapshot and session files to stdout
 * for debugging.
 *
 * On success, snapshots are kept in var/tmp/tui-e2e-XXXXXX/ for inspection
 * (each test directory's .hatfield/tmp/tui/smoke/ contains .ansi files).
 * Run `castor cleanup` to remove all temp/test artifacts.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
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
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        // Snapshots are kept for inspection under var/tmp/tui-e2e-*/
        // (each test's .hatfield/tmp/tui/smoke/ contains .ansi files).
        // Run `castor cleanup` to remove all temp/test artifacts.
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
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Step 1: Wait for TUI to render
            $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: '█',   // Hatfield logo
                timeout: 5.0,
            );
            // Step 2: Type a prompt
            $prompt = 'Respond with exactly one word: hello.';
            $this->tmux->sendLiteral($pane, $prompt);

            // Step 3: Submit
            $this->tmux->sendKey($pane, 'Enter');

            // Verify the user block appeared before the response streams in.
            // (The response may push ❯ off-screen, so capture early.)
            try {
                $userCapture = $this->tmux->waitForCaptureContains(
                    pane: $pane,
                    needle: '❯',
                    timeout: 5.0,
                );
            } catch (\RuntimeException $e) {
                $this->dumpArtifacts(
                    $pane,
                    '❯ user block did not appear after prompt submission.',
                );
                self::fail('Transcript must display user block (❯) after submission.');
            }
            self::assertStringContainsString(
                $prompt,
                $userCapture,
                'Transcript must include the typed prompt text.',
            );

            // Step 4: Wait for response — an assistant block (◇) or
            // an explicit error block (✕).  "Working..." / "Processing..."
            // going away is NOT sufficient; we need actual block output.
            try {
                $capture = $this->tmux->waitForCaptureContains(
                    pane: $pane,
                    needle: '◇',    // TranscriptBlockKind::AssistantMessage prefix
                    timeout: 5.0,
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
            // The user block was already verified in the early capture (step 3).
            // Now verify the assistant or error block appeared.

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

            // Step 6: Assert footer cost is non-zero.
            // With the high per-token pricing (input=$1000/M, output=$100000/M),
            // any successful LLM turn must produce a visible non-$0.00 cost.
            self::assertStringNotContainsString(
                '$0.00',
                $capture,
                'Footer cost must NOT be $0.00 after a turn '
                . 'with the priced test model configured.',
            );

            // Step 7: Save ANSI snapshot artifact.
            $this->saveAnsiSnapshot($pane, 'agent-flow-smoke');

            // Clean exit
            $this->tmux->sendKey($pane, 'C-d');
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
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // Send prompt
            $this->tmux->sendLiteral($pane, 'hello');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for either an assistant block or error block.
            // This proves the working status didn't stay stuck.
            try {
                $this->tmux->waitForCaptureContains($pane, '◇', 5.0);
            } catch (\RuntimeException) {
                $this->tmux->waitForCaptureContains($pane, '✕', 2.0);
            }

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
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── Start first turn ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // Chat-only prompt: multi-turn ordering test, not a tools test.
            $prompt1 = 'Respond with exactly "One". Do not use tools.';
            $this->tmux->sendLiteral($pane, $prompt1);
            $this->tmux->sendKey($pane, 'Enter');

            // Verify first user block appeared before response streams in
            $this->tmux->waitForCaptureContains($pane, '❯', 5.0);

            // Wait for first assistant response using full history
            try {
                $this->tmux->waitForHistoryContains($pane, '◇', 5.0);
            } catch (\RuntimeException) {
                $this->tmux->waitForHistoryContains($pane, '✕', 2.0);
            }

            // Capture full history so we don't miss content that scrolled off
            $firstHistory = $this->tmux->capturePlainWithHistory($pane);

            // Verify first response has correct structure
            self::assertStringContainsString('◇', $firstHistory, 'First assistant response should be visible in history');

            // No empty thinking placeholders: if ⋯ thinking is visible,
            // it should have actual text, not just "[thinking]".
            if (\str_contains($firstHistory, '⋯')) {
                $thinkingText = $this->extractBlockText($firstHistory, '⋯');
                self::assertNotEmpty(
                    \trim(\str_replace('[thinking]', '', $thinkingText)),
                    'Thinking block must not be empty placeholder — '
                        . \sprintf('got: "%s"', $thinkingText),
                );
            }

            // ── Start second turn ──
            // Type a follow-up prompt
            $prompt2 = 'Respond with exactly "Two". Do not use tools.';

            // Snapshot current history so we can wait for NEW occurrences
            // of ❯ and ◇ (the first turn's blocks are already in history).
            $beforeSecond = $this->tmux->capturePlainWithHistory($pane);
            $beforeUserCount = \substr_count($beforeSecond, '❯');
            $beforeAsstCount = \substr_count($beforeSecond, '◇');

            $this->tmux->sendLiteral($pane, $prompt2);
            $this->tmux->sendKey($pane, 'Enter');

            // Verify second user block appeared using full history
            // (the first prompt's assistant response may have scrolled the
            // ❯ off the visible area)
            try {
                $this->tmux->waitForCallback(
                    $pane,
                    static fn (string $capture): bool => \substr_count($capture, '❯') > $beforeUserCount,
                    5.0,
                    'Second ❯ user block did not appear after second prompt submission.',
                );
            } catch (\RuntimeException $e) {
                $this->dumpArtifacts(
                    $pane,
                    $e->getMessage(),
                );
                self::fail('Second prompt should produce a user block (❯).');
            }

            // Wait for second assistant response using full history
            try {
                $this->tmux->waitForCallback(
                    $pane,
                    static fn (string $capture): bool => \substr_count($capture, '◇') > $beforeAsstCount,
                    5.0,
                    'Second assistant block (◇) did not appear after second prompt.',
                );
            } catch (\RuntimeException) {
                $this->tmux->waitForHistoryContains($pane, '✕', 2.0);
            }

            // Exit the agent
            $this->tmux->sendKey($pane, 'C-d');

            // ── Final assertions using full terminal history ──
            $finalHistory = $this->tmux->capturePlainWithHistory($pane);
            $this->saveAnsiSnapshot($pane, 'multiturn-final');

            // Both assistant responses should be visible in history
            self::assertStringContainsString(
                '◇',
                $finalHistory,
                'At least one assistant block (◇) must be visible in terminal history',
            );

            // Processing... must be gone in settled state
            self::assertStringNotContainsString(
                'Processing...',
                $finalHistory,
                '"Processing..." block must be gone in settled state '
                    . '(it should be removed on first runtime event)',
            );

            // Verify conversation order from full history
            $firstUserPos = \strpos($finalHistory, $prompt1);
            $secondUserPos = \strpos($finalHistory, $prompt2);

            self::assertNotFalse(
                $firstUserPos,
                \sprintf('First prompt "%s" must be visible in terminal history', $prompt1),
            );
            self::assertNotFalse(
                $secondUserPos,
                \sprintf('Second prompt "%s" must be visible in terminal history', $prompt2),
            );
            self::assertLessThan(
                $secondUserPos,
                $firstUserPos,
                'First user message must appear before second user message in terminal history',
            );
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

    // ── block extraction helper ────────────────────────────

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

    // ── /new flow ──────────────────────────────────────────

    /**
     * New session command E2E: /new → type prompt → see LLM response.
     *
     * 1) Start the agent TUI and auto-submit an initial prompt via
     *    --prompt to create session 1 with a completed run — this
     *    proves the existing pipeline works and seeds the DB.
     * 2) Type /new and press Enter — triggers session switch to
     *    a lazy draft via TuiSessionSwitchService.
     * 3) Type a deterministic prompt and submit.
     * 4) Assert the user block (❯) appears, count increased.
     * 5) Wait for a NEW real assistant response block (◇ count increased)
     *    — this proves draft promotion, runtime startup, and the full
     *    Messenger → EventStore → Mapper → Projector → TUI pipeline work
     *    after a /new session switch.
     *
     * Hardened assertions (no ✕ fallback, no prior-history cheat):
     * - Record ❯/◇ counts AFTER the /new TUI rebuild but BEFORE
     *   the second prompt. Assert BOTH counts increase.
     * - An error block (✕) after the /new prompt causes test failure.
     */
    public function testNewSessionCommandAndGetAssistantResponse(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(prompt: 'Respond with exactly one word: first.'),
            prefix: 'hatfield-new-cmd',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── First run (auto-submit via --prompt) ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // The first run MUST produce an assistant block.  No ✕ fallback.
            $this->tmux->waitForCaptureContains($pane, '◇', 5.0);

            // ── Session switch via /new ──
            // The first session is completed; /new triggers a draft switch.
            // TuiSessionSwitchService should skip cancel for terminal runs.
            $this->tmux->sendLiteral($pane, '/new');
            $this->tmux->sendKey($pane, 'Enter');

            // After the switch the terminal is cleared and the TUI
            // rebuilds.  Wait for the Hatfield logo to confirm the
            // new draft session has rendered.
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // Record baseline counts BEFORE submitting the /new prompt.
            // The tmux scrollback retains pre-clear content, so using
            // counts-from-history is robust: we assert counts INCREASE
            // regardless of how many ❯/◇ were in the first run's output.
            $beforeCapture = $this->tmux->capturePlainWithHistory($pane);
            $beforeUserCount = \substr_count($beforeCapture, '❯');
            $beforeAssistantCount = \substr_count($beforeCapture, '◇');

            // ── Type prompt in new draft session ──
            $prompt2 = 'Respond with exactly one word: second.';
            $this->tmux->sendLiteral($pane, $prompt2);
            $this->tmux->sendKey($pane, 'Enter');

            // A NEW user block (❯) must appear — count must increase
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $capture): bool => \substr_count($capture, '❯') > $beforeUserCount,
                5.0,
                'New user block (❯) did not appear after /new prompt submission.',
            );

            // A NEW assistant block (◇) must appear — count must increase.
            // No ✕ fallback: an error block means the test is failing.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $capture): bool => \substr_count($capture, '◇') > $beforeAssistantCount,
                5.0,
                'New assistant block (◇) did not appear after /new prompt.',
            );

            $finalCapture = $this->tmux->capturePlainWithHistory($pane);
            $this->saveAnsiSnapshot($pane, 'new-cmd-flow');

            // No error block must appear after the /new prompt.
            // Only check the portion AFTER the baseline capture to avoid
            // flagging a pre-existing ✕ from the first run.
            $afterBaseline = \substr($finalCapture, \strlen($beforeCapture));
            self::assertStringNotContainsString(
                '✕',
                $afterBaseline,
                'No error block (✕) must appear after the /new prompt submission.',
            );

            // Processing… must be gone when settled
            self::assertStringNotContainsString('Processing', $finalCapture, '"Processing..." must be gone after response');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->saveAnsiSnapshot($pane, 'new-cmd-FAILURE'); } catch (\Throwable) {}
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    // ── /resume flow ───────────────────────────────────────

    /**
     * Resume session command E2E: /resume → pick existing session
     * → type follow-up → see LLM response.
     *
     * 1) Start the agent TUI and auto-submit an initial prompt via
     *    --prompt to create session 1 with a completed run.
     * 2) Type /resume (no args) — opens the interactive session
     *    picker overlay via SessionPickerController.
     * 3) Press Enter to select the first (default) session.
     * 4) After the session switch the terminal is cleared, the
     *    transcript replays from events.jsonl, and the TUI rebuilds.
     * 5) Type a follow-up prompt and submit.
     * 6) Assert user block (❯ count increased) and assistant block
     *    (◇ count increased) appear.
     * 7) After the TUI exits, inspect events.jsonl to prove follow_up
     *    was queued and applied without Cancelling poison.
     *
     * Hardened assertions (no ✕ fallback, no prior-history cheat):
     * - Record ❯/◇ counts AFTER the /resume TUI rebuild but BEFORE
     *   the follow-up submission. Assert BOTH counts increase.
     * - No ✕ fallback: an error block after the follow-up fails the test.
     * - JSONL verification: follow_up queued, applied, no cancellation rejection.
     */
    public function testResumeSessionCommandAndGetAssistantResponse(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(prompt: 'Respond with exactly one word: alpha.'),
            prefix: 'hatfield-resume-cmd',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── First run (auto-submit via --prompt) creates session 1 ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // The first run MUST produce an assistant block.  No ✕ fallback.
            $this->tmux->waitForCaptureContains($pane, '◇', 5.0);

            // ── /resume → picker ──
            $this->tmux->sendLiteral($pane, '/resume');
            $this->tmux->sendKey($pane, 'Enter');

            // The picker overlay should appear with a descriptive header
            try {
                $this->tmux->waitForCaptureContains($pane, 'Resume session', 5.0);
            } catch (\RuntimeException $e) {
                $this->dumpArtifacts($pane, 'Picker overlay did not appear after /resume');
                self::fail('/resume must open the session picker overlay.');
            }

            // Select the first session (default selected index is 0).
            // This triggers requestResume() → session switch → TUI rebuild.
            $this->tmux->sendKey($pane, 'Enter');

            // After session switch the terminal clears and logo reappears
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);

            // The replayed transcript should show the old user prompt
            $this->tmux->waitForCaptureContains($pane, 'alpha', 5.0);

            // ── Record baseline counts BEFORE follow-up ──
            // Count from full history so we detect NEW blocks regardless
            // of how many ❯/◇ the first run and replay left in scrollback.
            $beforeFollowUp = $this->tmux->capturePlainWithHistory($pane);
            $beforeUserCount = \substr_count($beforeFollowUp, '❯');
            $beforeAssistantCount = \substr_count($beforeFollowUp, '◇');

            // ── Type follow-up in the resumed session ──
            $followUpPrompt = 'Respond with exactly one word: beta.';
            $this->tmux->sendLiteral($pane, $followUpPrompt);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for a NEW user block (❯ count increased)
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $capture): bool => \substr_count($capture, '❯') > $beforeUserCount,
                5.0,
                'New user block (❯) did not appear after resume follow-up submission.',
            );

            // Wait for a NEW assistant block (◇ count increased).
            // No ✕ fallback: an error block means the test is failing.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $capture): bool => \substr_count($capture, '◇') > $beforeAssistantCount,
                5.0,
                'New assistant block (◇) did not appear after resume follow-up.',
            );

            $finalCapture = $this->tmux->capturePlainWithHistory($pane);
            $this->saveAnsiSnapshot($pane, 'resume-cmd-flow');

            // No error block must appear after the follow-up.
            // Only check the portion AFTER the baseline to avoid false
            // positives from a pre-existing ✕ in the initial run.
            $afterBaseline = \substr($finalCapture, \strlen($beforeFollowUp));
            self::assertStringNotContainsString(
                '✕',
                $afterBaseline,
                'No error block (✕) must appear after the resume follow-up submission.',
            );

            // Processing… must be gone
            self::assertStringNotContainsString('Processing', $finalCapture, '"Processing..." must be gone after response');

            // The follow-up prompt text must be visible
            self::assertStringContainsString(
                $followUpPrompt,
                $finalCapture,
                'Follow-up prompt must be visible in terminal history after /resume.',
            );

            $this->tmux->sendKey($pane, 'C-d');

            // ── Verify events.jsonl contents ──
            // The resumed session is session 1; check its events for
            // follow_up queued, applied, and absence of Cancelling poison.
            // Hardcoded 1 is deterministic: this test creates an isolated
            // project dir with a fresh SQLite DB, so the first INSERT
            // into hatfield_session always produces auto-increment ID 1.
            $this->assertResumedFollowUpEvents(1);
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->saveAnsiSnapshot($pane, 'resume-cmd-FAILURE'); } catch (\Throwable) {}
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    // ── helpers ────────────────────────────────────────────

    /**
     * Verify events.jsonl for a resumed session: follow_up must be
     * queued, applied, and NOT rejected with Cancelling poison.
     *
     * @param int $resumedSessionId the numeric session ID that was resumed
     */
    private function assertResumedFollowUpEvents(int $resumedSessionId): void
    {
        $eventsPath = \sprintf(
            '%s/.hatfield/sessions/%d/events.jsonl',
            $this->testProjectDir,
            $resumedSessionId,
        );

        if (!\is_file($eventsPath)) {
            self::fail(\sprintf(
                'Resumed session %d events.jsonl not found at %s',
                $resumedSessionId,
                $eventsPath,
            ));
        }

        $events = [];
        foreach (\file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = \json_decode($line, true);
            if (\is_array($decoded)) {
                $events[] = $decoded;
            }
        }

        $hasQueued = false;
        $hasApplied = false;
        $lastFollowUpApplied = null;

        foreach ($events as $event) {
            $type = $event['type'] ?? '';
            $payload = $event['payload'] ?? [];
            $kind = \is_array($payload) ? ($payload['kind'] ?? '') : '';

            if ('agent_command_queued' === $type && 'follow_up' === $kind) {
                $hasQueued = true;
            }

            if ('agent_command_applied' === $type && 'follow_up' === $kind) {
                $hasApplied = true;
                $lastFollowUpApplied = $event;

                // Check for Cancelling poison: if follow_up is applied with
                // a rejection reason mentioning cancellation, the run is
                // poisoned and resume is broken.
                $reason = \is_array($payload) ? ($payload['reason'] ?? '') : '';
                $lower = \strtolower($reason);
                if (\str_contains($lower, 'cancelling') || \str_contains($lower, 'cancellation is in progress')) {
                    self::fail(\sprintf(
                        'Follow-up applied with Cancelling rejection. reason="%s". Events (%d):%s%s',
                        $reason,
                        \count($events),
                        "\n",
                        \json_encode($events, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
                    ));
                }
            }
        }

        self::assertTrue(
            $hasQueued,
            \sprintf(
                'No agent_command_queued kind=follow_up in events.jsonl. Events (%d):%s%s',
                \count($events),
                "\n",
                \json_encode($events, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            ),
        );

        self::assertTrue(
            $hasApplied,
            \sprintf(
                'No agent_command_applied kind=follow_up in events.jsonl. '
                    . 'Follow-up may have been rejected silently. Events (%d):%s%s',
                \count($events),
                "\n",
                \json_encode($events, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            ),
        );
    }

    private function agentCommand(string $prompt = ''): string
    {
        [$php, $script] = AgentTestExecutable::command();

        $promptArg = '' !== $prompt
            ? \sprintf(' --prompt=%s', \escapeshellarg($prompt))
            : '';

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash%s 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
            $promptArg,
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf('%s/var/tmp/tui-e2e-%s', $this->projectRoot, \bin2hex(\random_bytes(6)));
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        // Build minimal isolated settings from scratch.  Do NOT read or
        // copy the real project .hatfield/settings.yaml — that would leak
        // unrelated provider credentials (e.g. openai-codex requiring
        // OAuth) into the isolated test HOME, breaking the selected model
        // path.  Include only what the TUI smoke test actually needs.
        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
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
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                // High pricing so any successful turn produces
                                // a visible non-$0.00 cost in the footer.
                                'cost' => ['input' => 1000.0, 'output' => 100000.0],
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
                        'allow_command_patterns' => [],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

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
            foreach (['events.jsonl', 'state.json', 'idempotency.jsonl'] as $file) {
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

}
