<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that the exact model-facing output-cap notice text is visible
 * in the TUI transcript when replaying a session from events.jsonl.
 *
 * Creates a real session by sending a prompt (backed by replay fixture),
 * then replaces the session's events.jsonl with a fixture containing a
 * capped tool result.  /resume <sessionId> replays the fixture through
 * the translator and projector, and the TUI shows the ToolResult block
 * (styled with a ⚠ warning icon) containing the exact cap notice text.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiOutputCapNoticeE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
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
     * Create a real session by submitting a prompt, replace its events.jsonl
     * with a capped-output fixture, then /resume and assert the System
     * notice is visible.
     */
    public function testOutputCapNoticeVisibleOnResume(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-output-cap',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── Phase 1: Create a real session ──
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            usleep(500_000);

            // Submit a prompt to create the session (LLM fixture responds).
            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, 'Hello');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant response (◇) and extract numeric session ID.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 15.0,
                message: 'Neither ◇ assistant block nor ✕ error appeared after first submit',
                history: 2000,
            );

            $firstCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $matched = preg_match('/session\s+(\d+)/', $firstCapture, $matches);
            $this->assertSame(1, $matched,
                'Footer must show numeric session ID after first submit');
            $sessionId = $matches[1];

            // ── Phase 2: Replace events.jsonl with output-cap fixture ──
            $this->writeCappedOutputFixture($sessionId);

            // ── Phase 3: /resume <sessionId> ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            // Wait for the output-cap System notice block (⚠) with the exact
            // model-facing "[Output capped to ...]" notice text from
            // model_input_messages (central-cap path).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '[Output capped to'),
                timeout: 10.0,
                message: 'Output cap System notice block did not appear in transcript after resume',
                history: 3000,
            );

            // Capture full visible pane for assertions.
            $visiblePane = $this->tmux->capturePlainWithHistory($pane, 3000);

            // 1. The ⚠ warning icon must be visible on the System notice block prefix.
            $this->assertStringContainsString('⚠', $visiblePane,
                'Output-cap System notice block warning icon must be visible in transcript');

            // 2. The exact model-facing cap notice must be visible verbatim
            //    in the System notice block (from model_input_messages, not
            //    from tool_execution_end.result).
            $this->assertStringContainsString('[Output capped to', $visiblePane,
                'Model-facing cap notice text must be visible in transcript');

            // 3. Full output size from the exact notice.
            $this->assertStringContainsString('Full output: 50000 characters', $visiblePane,
                'Full output size from exact model-facing notice must be visible');

            // 4. Saved path from the exact notice.
            $this->assertStringContainsString('Saved for audit at: /tmp/cap/', $visiblePane,
                'Saved path from exact model-facing notice must be visible');

            // 5. Follow-up guidance from the exact notice.
            $this->assertStringContainsString('Do NOT rerun', $visiblePane,
                'Do-not-rerun instruction from exact cap notice must be visible');
            $this->assertStringContainsString('read path=', $visiblePane,
                'Read-path guidance from exact cap notice must be visible');

            // 6. Compact ToolResult status ("read completed") must be visible.
            $this->assertStringContainsString('read completed', $visiblePane,
                'Compact ToolResult status must be visible instead of raw output');

            // 7. Raw tool output from tool_execution_end.result must NOT be
            //    visible — the ToolResult block was compacted.
            $this->assertStringNotContainsString('RAW FULL TOOL OUTPUT', $visiblePane,
                'Raw tool output text must NOT survive after model_input_messages projection');

            // 8. Generated user-role model input projected as System block.
            $this->assertStringContainsString('Tool result image for view_image', $visiblePane,
                'Generated user-role model input (System block) must be visible with exact text');

            // 9. No paraphrase/synthesis — the TUI shows exactly what the model saw.
            $this->assertStringNotContainsString('Output exceeded', $visiblePane,
                'Must NOT contain paraphrased "Output exceeded" text');
            $this->assertStringNotContainsString('Model was shown', $visiblePane,
                'Must NOT contain paraphrased "Model was shown" text');
            $this->assertStringNotContainsString('visible chars', $visiblePane,
                'Must NOT contain old paraphrased "visible chars" text');

            // 10. The session ID appears in the footer.
            $this->assertStringContainsString($sessionId, $visiblePane,
                'The exact session ID should be visible in the footer');

            // 9. The session ID appears in the footer.
            $this->assertStringContainsString($sessionId, $visiblePane,
                'The exact session ID should be visible in the footer');

            // Save ANSI snapshot.
            $this->saveAnsiSnapshot($pane, 'output-cap-notice');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'output-cap-notice-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Session fixture ────────────────────────────────────────────────

    /**
     * Write a custom events.jsonl with a tool execution whose result text
     * is raw (uncapped) and carried by the subsequent llm_step_completed's
     * model_input_messages payload to prove the central-cap path.
     */
    private function writeCappedOutputFixture(string $sessionId): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';

        $events = [];

        // Turn 0: run_started
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 1, 'turn_no' => 0, 'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Read the large file']]],
                    ],
                ],
            ],
            'ts' => $now,
        ];

        // ── Turn 1: tool call → capped tool execution → assistant response ──
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 2, 'turn_no' => 1, 'type' => 'turn_advanced',
            'payload' => ['step_id' => 'turn-1', 'turn_no' => 1, 'parent_turn_no' => null],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 3, 'turn_no' => 1, 'type' => 'leaf_set',
            'payload' => ['turn_no' => 1, 'previous_turn_no' => null, 'parent_turn_no' => null, 'reason' => 'continue'],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 4, 'turn_no' => 1, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-1',
                'stop_reason' => 'tool_call',
                'text' => null,
                'tool_calls_count' => 1,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_read_cap_001',
                            'name' => 'read',
                            'arguments' => ['path' => '/tmp/large.txt'],
                            'order_index' => 0,
                        ],
                    ],
                ],
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 5, 'turn_no' => 1, 'type' => 'tool_execution_start',
            'payload' => [
                'tool_call_id' => 'call_read_cap_001',
                'tool_name' => 'read',
                'order_index' => 0,
                'mode' => 'sequential',
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 6, 'turn_no' => 1, 'type' => 'tool_call_result_received',
            'payload' => [
                'tool_call_id' => 'call_read_cap_001',
                'order_index' => 0,
                'is_error' => false,
            ],
            'ts' => $now,
        ];
        // tool_execution_end WITH raw (uncapped) result — the model-facing
        // capped text is delivered later via model_input_messages in
        // the following llm_step_completed so the TUI proves the
        // central-cap path.
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 7, 'turn_no' => 1, 'type' => 'tool_execution_end',
            'payload' => [
                'tool_call_id' => 'call_read_cap_001',
                'order_index' => 0,
                'is_error' => false,
                'result' => 'RAW FULL TOOL OUTPUT THAT SHOULD NOT REMAIN VISIBLE AFTER MODEL INPUT PROJECTION',
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 8, 'turn_no' => 1, 'type' => 'message_start',
            'payload' => ['message_role' => 'tool', 'tool_call_id' => 'call_read_cap_001'],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 9, 'turn_no' => 1, 'type' => 'message_end',
            'payload' => ['message_role' => 'tool', 'tool_call_id' => 'call_read_cap_001'],
            'ts' => $now,
        ];
        // llm_step_completed WITH model_input_messages — this is the
        // central-cap path proving the exact model-facing text (tool
        // and generated user messages) is projected into the TUI.
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 10, 'turn_no' => 1, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-1',
                'stop_reason' => 'stop',
                'text' => 'The file was too large so only the beginning is shown.',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'The file was too large so only the beginning is shown.']],
                ],
                'model_input_messages' => [
                    [
                        'role' => 'tool',
                        'tool_call_id' => 'call_read_cap_001',
                        'tool_name' => 'read',
                        'text' => "[Output capped to 20000 characters]\n\nFull output: 50000 characters (~12500 tokens).\nSaved for audit at: /tmp/cap/cap-lines.txt\n\nDo NOT rerun the same full command/tool call.\nDo NOT read the saved file in full.\n\nUse targeted tool calls to continue reading:\n• Read more from the file: `read path=<path> offset=<next_line> limit=<lines>`\n• Search for relevant content or ask for a summary\n\nIf you must inspect the raw saved output, use `read` with a small window.\n",
                        'source' => 'tool_result',
                        'metadata' => ['output_cap' => true, 'output_cap_limit' => 20000],
                    ],
                    [
                        'role' => 'user',
                        'text' => 'Tool result image for view_image: /tmp/cap/screenshot.png (image/png, 640x480, 12345 bytes)',
                        'source' => 'tool_result_image',
                        'tool_call_id' => 'call_read_cap_001',
                        'tool_name' => 'read',
                        'metadata' => [
                            'model_input_generated' => true,
                            'has_non_text_content' => true,
                        ],
                    ],
                ],
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 11, 'turn_no' => 1, 'type' => 'turn_end',
            'payload' => ['turn_no' => 1],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 12, 'turn_no' => 1, 'type' => 'agent_end',
            'payload' => ['reason' => 'completed'],
            'ts' => $now,
        ];

        $jsonl = '';
        foreach ($events as $ev) {
            $jsonl .= json_encode($ev, \JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($eventsPath, $jsonl);
    }

    // ── Setup helpers ─────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-simple-text-response.json';
        if (!is_file($fixturePath)) {
            $this->fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $dbPath = 'app_test-tui-output-cap-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test '
                .'HATFIELD_TEST_DATABASE_PATH=%s '
                .'HOME=%s '
                .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
                .'%s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash '
                .'2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-output-cap');
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
