<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that output-cap notice System blocks are visible in the TUI
 * transcript when replaying a session from events.jsonl.
 *
 * Seeds a session's events.jsonl with a tool execution whose result text
 * contains an output cap notice marker ("[Output capped to ...]"). The
 * translator detects this and adds output_capped metadata; the tool
 * projection subscriber projects both a ToolResult block and a System
 * notice block.  On resume, the TUI must display the System notice.
 *
 * The resume approach (pre-populated events.jsonl + --session) is fully
 * deterministic: no live LLM, no replay fixture, no model interaction.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiOutputCapNoticeE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $sessionId;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
        $this->sessionId = 'output-cap-e2e-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Start an agent session that resumes from pre-populated events.jsonl
     * containing a tool execution with an output cap notice.
     *
     * Asserts:
     *  1. A System block heading (ℹ) is visible in the transcript.
     *  2. The system notice text mentions "Output was capped".
     *  3. The cap metadata appears in the transcript (character limit, total chars).
     *  4. A session ID appears in the footer.
     */
    public function testOutputCapNoticeVisibleOnResume(): void
    {
        // Seed events.jsonl BEFORE starting the agent.
        $this->seedEventsJsonl($this->sessionId);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-output-cap',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // Wait for TUI startup
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);
            usleep(500_000);

            // Send /resume command with the session ID.
            $this->tmux->sendLiteral($pane, '/resume '.$this->sessionId);
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the transcript to show a System block (ℹ indicator)
            // and the output cap notice text.  Give generous timeout for
            // replay processing.
            $fullCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Output was capped')
                    || str_contains($cap, 'ℹ'),
                timeout: 15.0,
                message: 'Output cap System notice did not appear in transcript after resume',
                history: 3000,
            );

            // Capture full history for detailed assertions.
            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 3000);

            // 1. The System notice heading (ℹ) must be visible.
            self::assertStringContainsString(
                'ℹ',
                $fullCapture,
                'System block indicator must be visible in transcript',
            );

            // 2. The "Output was capped" text must appear.
            self::assertStringContainsString(
                'Output was capped',
                $fullCapture,
                'Output cap notice text must be visible in transcript',
            );

            // 3. Cap metadata (character limit and total characters) must appear.
            self::assertStringContainsString(
                '20000 character limit',
                $fullCapture,
                'Output cap limit must be visible in transcript',
            );
            self::assertStringContainsString(
                '50000 total characters',
                $fullCapture,
                'Character count must be visible in transcript',
            );

            // 4. The "saved for audit" message must appear.
            self::assertStringContainsString(
                'saved for audit',
                $fullCapture,
                '"saved for audit" text must be visible in transcript',
            );

            // 5. A ToolResult block must also be visible.
            self::assertStringContainsString(
                'Output capped to',
                $fullCapture,
                'Tool result with capped text must be visible in transcript',
            );

            // 6. Verify session ID in footer.
            self::assertStringContainsString(
                'session ',
                $fullCapture,
                'Session ID should appear in footer after resume',
            );
            self::assertStringContainsString(
                $this->sessionId,
                $fullCapture,
                'The exact session ID should be visible in the footer',
            );

            // Save ANSI snapshot for inspection.
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

    // ── Session seeding ────────────────────────────────────────────────

    /**
     * Write a custom events.jsonl that simulates a session with:
     *
     *  Turn 1 (completed with capped tool output):
     *    - run_started (with user message)
     *    - turn_advanced → turn 1
     *    - llm_step_completed with tool_calls (read tool)
     *    - tool_execution_start
     *    - tool_execution_end WITH result containing output cap marker
     *    - message_end for tool role
     *    - llm_step_completed for assistant response
     *    - turn_end
     *    - agent_end
     *
     * The result text contains the canonical OutputCap marker
     * "[Output capped to {limit} characters]" which the translator
     * detects and adds output_capped metadata.  The tool projection
     * subscriber then produces both a ToolResult block and a System
     * notice block.
     */
    private function seedEventsJsonl(string $sessionId): void
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
        // llm_step_completed with tool_calls
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
        // Tool execution start
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
        // Tool call result received
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
        // tool_execution_end WITH capped result
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 7, 'turn_no' => 1, 'type' => 'tool_execution_end',
            'payload' => [
                'tool_call_id' => 'call_read_cap_001',
                'order_index' => 0,
                'is_error' => false,
                'result' => "[Output capped to 20000 characters]\n\nFull output: 50000 characters (~12500 tokens).\nSaved for audit at: /tmp/cap/cap-lines.txt\n\nDo NOT rerun the same full command.\nDo NOT read the saved file in full.\n\nInstead, use targeted tool calls to continue:\n• Read more from a file: `read path=<path> offset=<next_line> limit=<lines>`\n• Search for known text: `grep pattern=<pattern> path=<path>`\n• Request a summary of the output and I will help.\n\nIf you must inspect the raw saved output, use `read` with a small offset and limit.\n",
            ],
            'ts' => $now,
        ];
        // Tool message events (for lifecycle validity)
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
        // Assistant response after tool
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 10, 'turn_no' => 1, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-1',
                'stop_reason' => 'stop',
                'text' => 'The file was too large so only the beginning is shown. Use targeted reads to get the specific parts you need.',
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'The file was too large so only the beginning is shown. Use targeted reads to get the specific parts you need.']],
                ],
            ],
            'ts' => $now,
        ];
        // Turn end
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 11, 'turn_no' => 1, 'type' => 'turn_end',
            'payload' => ['turn_no' => 1],
            'ts' => $now,
        ];
        // Agent end
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

        @\mkdir(\dirname($eventsPath), 0o777, true);
        file_put_contents($eventsPath, $jsonl);
    }

    // ── Setup helpers ─────────────────────────────────────────────────

    private function agentCommand(): string
    {
        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $dbPath = 'app_test-tui-output-cap-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash,bg_status,edit,write,grep 2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-output-cap');
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/.hatfield/sessions', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

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
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
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
}
