<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for immediate visual feedback after message submit.
 *
 * Verifies that after pressing Enter, the working indicator (◐) appears
 * quickly — before the heavy synchronous work (session creation, context
 * discovery, runner start) and before the LLM response streams in.
 *
 * Design:
 *  - Starts the agent TUI in a detached tmux session.
 *  - Types a prompt, submits via Enter.
 *  - Immediately polls the terminal content for the working indicator.
 *  - Asserts the working indicator appears within a tight threshold (1s),
 *    proving the render happens before the LLM response.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class ImmediateSubmitFeedbackTest extends TestCase
{
    private TmuxHarness $tmux;
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
    }

    protected function tearDown(): void
    {
        // TmuxHarness destructor kills all sessions.
    }

    /**
     * @test
     *
     * After submit, the working indicator (◐) appears quickly.
     *
     * Proof strategy:
     *  1. Start the TUI.
     *  2. Wait for boot.
     *  3. Confirm the idle indicator is visible before submit.
     *  4. Submit a prompt via Enter.
     *  5. Wait for the working indicator (◐) to appear.
     *  6. Assert the elapsed time is within a tight bound.
     *  7. Wait for the assistant response to prove the pipeline works.
     */
    public function testWorkingIndicatorAppearsQuicklyAfterSubmit(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-submit-feedback',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo █ visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Verify the idle indicator (●) is visible before submit.
        // The WorkingStatusWidget shows "● idle" when no run is active.
        $preSubmit = $this->tmux->capturePlain($pane);
        self::assertStringContainsString(
            '● idle',
            $preSubmit,
            'Idle indicator should be visible before prompt submission',
        );

        // Type and submit a prompt.
        $this->tmux->sendLiteral($pane, 'hello');
        $this->tmux->sendKey($pane, 'Enter');

        // Measure how quickly the working indicator (◐) appears after submit.
        // The SubmitListener now forces an immediate terminal render after
        // showing the working message, so it should be near-instant.
        $start = \microtime(true);
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $capture): bool => \str_contains($capture, '◐'),
            timeout: 10.0,
            message: 'Working indicator (◐) did not appear after prompt submission',
        );
        $elapsed = \microtime(true) - $start;

        // The working indicator MUST appear within 1 second of submit.
        // The actual render happens synchronously in the TUI event handler
        // (processRender inside SubmitListener), so this should be well
        // under 100ms on any reasonable system.  1s provides a generous
        // margin for tmux overhead without allowing the LLM response to
        // race ahead (llama.cpp responses on port 9052 typically take
        // 2-10s even for trivial prompts).
        self::assertLessThan(
            1.0,
            $elapsed,
            \sprintf(
                'Working indicator (◐) should appear within 1s of submit, but took %.2fs',
                $elapsed,
            ),
        );

        // Prove the full pipeline still works: wait for the assistant
        // response (◇) to verify we didn't break the LLM path.
        try {
            $this->tmux->waitForCaptureContains($pane, '◇', 10.0);
        } catch (\RuntimeException) {
            // Maybe the LLM failed — try error block.
            $this->tmux->waitForCaptureContains($pane, '✕', 2.0);
        }

        $finalCapture = $this->tmux->capturePlainWithHistory($pane, 500);
        self::assertTrue(
            \str_contains($finalCapture, '◇') || \str_contains($finalCapture, '✕'),
            'Full pipeline must produce an assistant or error block after submit',
        );

        // Clean exit.
        $this->tmux->sendKey($pane, 'C-d');
    }

    /**
     * @test
     *
     * After submit, the user message block (❯) appears in transcript.
     *
     * The user message is projected from the run.started runtime event.
     * This verifies the canonical transcript projection still works
     * correctly after the Working indicator change — no duplicate user
     * messages, and the user message block appears within a reasonable
     * time.
     */
    public function testUserMessageAppearsAfterSubmit(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-user-msg',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for boot.
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        $prompt = 'test user message visibility';
        $this->tmux->sendLiteral($pane, $prompt);
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the user message block (❯) to appear.
        try {
            $this->tmux->waitForCaptureContains($pane, '❯', 5.0);
        } catch (\RuntimeException $e) {
            self::fail('User message block (❯) must appear after submit: ' . $e->getMessage());
        }

        $capture = $this->tmux->capturePlainWithHistory($pane, 500);

        // The user prompt text should be visible.
        self::assertStringContainsString(
            $prompt,
            $capture,
            'Transcript must display the submitted prompt text',
        );

        // There should be exactly ONE ❯ for this user message.
        // (The boot process and startup messages should not produce a ❯.)
        $userBlockCount = \substr_count($capture, '❯');
        self::assertSame(
            1,
            $userBlockCount,
            \sprintf(
                'Expected exactly 1 user block (❯) after first submit, got %d',
                $userBlockCount,
            ),
        );

        // Clean exit.
        $this->tmux->sendKey($pane, 'C-d');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir . '/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory with the test LLM provider.
     */
    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-submit-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir . '/.hatfield', 0o777, true);
        @\mkdir($dir . '/home/.hatfield', 0o777, true);

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
                        'supports_thinking_levels' => false,
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => false,
                                'cost' => [
                                    'input' => 0,
                                    'output' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir . '/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir . '/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
