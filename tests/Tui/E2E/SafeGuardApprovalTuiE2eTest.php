<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E test proving the SafeGuard blocking-poll approval overlay works
 * in the real interactive TUI via TmuxHarness.
 *
 * Flow (end-to-end, no mocked services):
 *   1. TUI starts with --prompt that triggers a write tool call outside CWD
 *   2. Replay fixture returns write tool-call deltas (no live LLM)
 *   3. SafeGuard intercepts with RequireApproval
 *   4. ExtensionToolHookEventSubscriber creates a ToolQuestion and
 *      blocks in a polling loop in the tool consumer process
 *   5. Controller ToolQuestionPoller emits tool_question.requested
 *   6. TUI TickPollListener inspects schema (has enum) → renders Choice overlay
 *      with SafeGuard's enum values: "Allow once" / "Always allow" / "Deny"
 *   7. Test detects "Allow once" in the TUI capture and presses Enter
 *   8. onAnswer closure sends answer_tool_question with kind=approval (generic)
 *   9. AnswerToolQuestionHandler routes by stored schema → answerWithText → poll returns → tool executes
 *   10. Write tool creates the file on disk outside CWD
 *   11. Second fixture streams "The file has been written." as assistant text
 *
 * @see TuiJourneyE2eTest for the standard journey pattern
 * @see SafeGuardApprovalControllerReplayTest for the controller-replay counterpart
 */
#[Group('tui-e2e-replay')]
final class SafeGuardApprovalTuiE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $targetOutsidePath;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();

        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);

        // Compute the expected target file path (same as in the fixture deltas).
        $sessionId = pathinfo($this->testProjectDir, \PATHINFO_BASENAME);
        $this->targetOutsidePath = \dirname($this->testProjectDir).'/sg-'.$sessionId.'.txt';
        @\unlink($this->targetOutsidePath);

        // Write replay fixture files into the isolated project dir.
        $this->writeFixtures($sessionId);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }

        // Clean up fixture files
        if (isset($this->testProjectDir) && '' !== $this->testProjectDir) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    // ── Fixture generation ────────────────────────────────────────

    /**
     * Write two replay fixtures into the isolated project dir:
     *   1. Write tool call (path outside CWD)
     *   2. "done" text response (post-tool assistant turn)
     */
    private function writeFixtures(string $sessionId): void
    {
        // Fixture 1: the LLM calls write with path ../sg-{sessionId}.txt
        $path = '../sg-'.$sessionId.'.txt';
        $callId = 'call_sg_tui_1';

        $fixture1 = [
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'deltas' => [
                ['type' => 'tool_call_start', 'id' => $callId, 'name' => 'write'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '{"path":"../sg'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '-'.$sessionId],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => '.txt","content":"h'],
                ['type' => 'tool_input_delta', 'id' => $callId, 'name' => 'write', 'partial_json' => 'ello"}'],
                ['type' => 'tool_call_complete', 'tool_calls' => [
                    ['id' => $callId, 'name' => 'write', 'arguments' => ['path' => $path, 'content' => 'hello']],
                ]],
            ],
            'stop_reason' => 'tool_call',
        ];

        // Fixture 2: the LLM returns text after the tool executes
        $fixture2 = [
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'deltas' => [
                ['type' => 'text', 'content' => 'The file has been written.'],
            ],
            'stop_reason' => 'stop',
        ];

        \file_put_contents(
            $this->testProjectDir.'/fixture-write.json',
            \json_encode($fixture1, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
        \file_put_contents(
            $this->testProjectDir.'/fixture-done.json',
            \json_encode($fixture2, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }

    // ── The test ──────────────────────────────────────────────────

    /**
     * Prove the interactive TUI approval overlay works end-to-end:
     *   - SafeGuard blocks a write outside CWD
     *   - The approval overlay renders with Allow once / Always allow / Deny
     *   - Selecting "Allow once" via Enter executes the write tool
     *   - The file is created on disk outside CWD
     *   - No "blocked" messaging appears
     */
    public function testSafeGuardApprovalOverlayAllowsWriteOutsideCwd(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-sg-approval',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Phase 1: Wait for the SafeGuard approval overlay to appear
            // in the TUI. The overlay shows "Allow once" as the first option
            // (default-selected) from handleApprovalToolQuestion's schema.
            $approvalCapture = $this->tmux->waitForCaptureContains(
                $pane,
                'Allow once',
                timeout: 25.0,
            );

            $this->saveAnsiSnapshot($pane, 'sg-approval-overlay');

            // Verify the approval overlay shows the expected 3 options
            self::assertStringContainsString('Allow once', $approvalCapture,
                'Approval overlay must show Allow once option');
            self::assertStringContainsString('Always allow', $approvalCapture,
                'Approval overlay must show Always allow option');
            self::assertStringContainsString('Deny', $approvalCapture,
                'Approval overlay must show Deny option');

            // Verify NO human_input.requested (the old interrupt flow is gone)
            self::assertStringNotContainsString('human_input.requested', $approvalCapture,
                'Blocking-poll must NOT produce human_input.requested event');

            // Phase 2: Accept "Allow once" by pressing Enter.
            // The first item ("Allow once") is default-selected by
            // SelectListWidget, so Enter confirms it directly.
            $this->tmux->sendKey($pane, 'Enter');

            // Phase 3: Wait for the write tool to execute and the
            // assistant response to appear. After the blocking poll
            // returns "Allow once", the real write handler runs,
            // the file is created, and the second fixture streams
            // "The file has been written."
            $resultCapture = $this->tmux->waitForCaptureContains(
                $pane,
                'The file has been written',
                timeout: 25.0,
            );

            $this->saveAnsiSnapshot($pane, 'sg-approval-complete');

            // Assert the write actually happened on disk (outside CWD)
            self::assertFileExists(
                $this->targetOutsidePath,
                'The write tool must create the file outside CWD after approval in the real TUI',
            );

            $written = \trim((string) \file_get_contents($this->targetOutsidePath));
            self::assertSame('hello', $written,
                'File content must match what the LLM wrote');

            // Assert NO "blocked" or "interrupt" messaging in the
            // full history (the old soft-interrupt flow is eliminated).
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 3000);
            self::assertStringNotContainsStringIgnoringCase('blocked', $fullCapture,
                'Must not show blocked messaging after approval');
            self::assertStringNotContainsStringIgnoringCase('interrupt', $fullCapture,
                'Must not show interrupt messaging after approval');

        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'sg-approval-FAILURE');
            throw $e;
        }
    }

    // ── Command building ──────────────────────────────────────────

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';
        $dbPath = 'app_test-tui-sg-'.bin2hex(random_bytes(4)).'.sqlite';

        $fixturePath = \implode(';', [
            $this->testProjectDir.'/fixture-write.json',
            $this->testProjectDir.'/fixture-done.json',
        ]);

        return \sprintf(
            'APP_ENV=test '
            .'HATFIELD_TEST_DATABASE_PATH=%s '
            .'HOME=%s '
            .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
            .'HATFIELD_APPROVAL_CHANNEL=controller '
            .'%s %s agent '
            .'--model=llama_cpp_test/test '
            .'--tools-excluded=bash '
            .'--prompt="Write a file to ../%s/sg-%s.txt with content hello" '
            .'2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg($php),
            \escapeshellarg($script),
            \basename($this->testProjectDir),
            \basename($this->testProjectDir),
        );
    }

    // ── Isolated project directory ────────────────────────────────

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-sg-approval');
        @\mkdir($dir.'/.hatfield', 0o777, true);

        // Settings with SafeGuard enabled, write tool tracked,
        // NO allow_write_outside_cwd patterns (so SafeGuard blocks).
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

        // Also write HOME dir settings (used by controller subprocess).
        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    // ── Snapshot helpers ──────────────────────────────────────────

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = \date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }
}
