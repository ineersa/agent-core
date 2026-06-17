<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that /resume <session-id> reconstructs canonical non-streaming
 * transcript blocks from events.jsonl — thinking, assistant text, tool-call
 * blocks, tool results — so the visible pane shows the same content the
 * user saw in the live session, with NO transient streaming remnants.
 *
 * Prior forks incorrectly blamed terminal clearing.  The real root cause
 * is twofold:
 *   1. The translator drops assistant_message.details.thinking and
 *      assistant_message.tool_calls from llm_step_completed, so the
 *      projector never creates thinking or tool-call blocks on replay.
 *   2. tool_execution_end without result leaves the ToolResult block
 *      stuck at "Running…" because upsertToolResultBlock preserves
 *      existing text when the incoming text is empty.
 *
 * This test seeds a session's events.jsonl with three turns:
 *   Turn 1: completed llm_step_completed with thinking + assistant text
 *           → proves thinking block reconstruction
 *   Turn 2: llm_step_completed with tool_calls, then tool_execution_start,
 *           tool_call_result_received, and tool_execution_end (no result)
 *           → proves tool-call block reconstruction + Running… replacement
 *   Turn 3: tool_execution_start + llm_step_aborted (cancellation)
 *           → proves streaming blocks removed on cancellation (565d9a9c7)
 *
 * After /resume the visible pane (capturePlain) must show:
 *  - Thinking content from turn 1
 *  - Assistant text from turn 1
 *  - Tool-call block with tool name and arguments from turn 2
 *  - Tool result showing tool name (not "Running…") for turn 2
 *  - Cancellation marker for turn 3
 *  - ZERO "● Running…" rows
 *  - ZERO raw escape sequences
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiResumeSessionSwitchE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testProjectDir)) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testResumeReconstructsCanonicalBlocksFromEventsJsonl(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-resume',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── Phase 1: Startup layout ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(200_000);

            // ── Phase 2: Create a session via "hi" + Enter ──
            $this->tmux->sendLiteral($pane, 'hi');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant block (◇) or error (✕).
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: 5.0,
                message: 'Neither ◇ assistant block nor ✕ error appeared after first submit',
                history: 2000,
            );

            // Extract session ID from footer history.
            $firstCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            self::assertStringContainsString('Hello from the test harness.', $firstCapture,
                'Replay response must be visible after first submit');

            $matched = preg_match('/session\s+(\d+)/', $firstCapture, $matches);
            self::assertSame(1, $matched,
                'Footer must show numeric session ID after first submit');
            $sessionId = $matches[1];

            // Wait for turn to complete.
            try {
                $this->tmux->waitForCallback(
                    $pane,
                    static fn (string $cap): bool => str_contains($cap, '◇')
                        && !str_contains($cap, '◐ Working...'),
                    timeout: 3.0,
                    message: 'Turn did not complete before session replacement',
                    history: 2000,
                );
            } catch (\RuntimeException) {
                // Non-fatal: may already be done.
            }

            $this->saveAnsiSnapshot($pane, 'resume-step1-first-session');

            // ── Phase 3: Replace events.jsonl with canonical fixture ──
            //
            // Overwrite the session's events.jsonl with a three-turn
            // fixture that exercises canonical reconstruction:
            //   Turn 1: thinking + text → proves thinking block recon
            //   Turn 2: tool_calls + tool result (no result text) →
            //           proves tool-call block recon + Running→ falling back
            //   Turn 3: cancelled turn → proves streaming removal
            $this->writeCanonicalFixture($sessionId);

            // ── Phase 4: /resume <sessionId> ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for header (█) in the VISIBLE PANE — proves re-render.
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(300_000);

            // ── Phase 5: Assert clean visible-pane layout ──
            // capturePlain() returns ONLY the currently visible pane,
            // not scrollback history.
            $visiblePane = $this->tmux->capturePlain($pane);

            // A) Structural TUI layout must be present.
            self::assertStringContainsString('█', $visiblePane,
                'Hatfield logo (header) must be visible in the current pane after /resume');
            self::assertStringContainsString('◆', $visiblePane,
                'Footer must be visible in the current pane after /resume');
            self::assertTrue(
                str_contains($visiblePane, '● idle') || str_contains($visiblePane, '◐ Work'),
                'Working/idle status must be visible in the current pane after /resume',
            );

            // B) Session ID must appear.
            self::assertStringContainsString($sessionId, $visiblePane,
                'Session ID must appear in the current pane after /resume');

            // ── C) Turn 1: thinking + assistant text ──
            //
            // The fixture's turn 1 has assistant_message with
            // details.thinking and text.  The projector must reconstruct
            // a non-streaming thinking block and a text block.

            self::assertStringContainsString('Let me think about this request carefully.', $visiblePane,
                'Thinking text from turn 1 must be visible after /resume');

            self::assertStringContainsString('Here is the answer you requested.', $visiblePane,
                'Assistant text from turn 1 must be visible after /resume');

            // ── D) Turn 2: tool-call block + tool result ──
            //
            // The fixture's turn 2 has assistant_message.tool_calls with
            // a read tool call, then tool_execution_start/end without
            // result text.  The projector must reconstruct a tool-call
            // block and replace "Running…" with the tool name.

            self::assertStringContainsString('read', $visiblePane,
                'Tool name must appear in tool-call block after /resume');
            self::assertStringContainsString('/tmp/example.txt', $visiblePane,
                'Tool arguments must appear in tool-call block after /resume');

            self::assertStringContainsString('read completed', $visiblePane,
                'Tool result must show "read completed" (fallback from tool name), not Running…');

            // ── E) Turn 3: cancellation ──
            self::assertStringContainsString('turn cancelled', $visiblePane,
                'Cancellation marker for turn 3 must be visible after /resume');

            // ── F) ZERO "● Running…" ──
            //
            // This is the core assertion.  Neither a completed tool
            // without result (turn 2) nor a cancelled turn (turn 3)
            // should leave "● Running…" in the visible pane.
            $runningCount = \substr_count($visiblePane, '● Running…');
            self::assertSame(0, $runningCount,
                \sprintf(
                    'Zero "● Running…" expected in current pane after /resume (found %d). '
                    .'Completed tools must fall back to tool name; cancelled tools must be removed.',
                    $runningCount,
                ));

            // ── G) No transient streaming fragments ──
            self::assertStringNotContainsString('◇ </think>', $visiblePane,
                'Transient thinking close tags must not appear after /resume');

            // ── H) No raw escape-sequence leakage ──
            self::assertStringNotContainsString('[2J', $visiblePane,
                'Escape [2J must not leak into visible pane');
            self::assertStringNotContainsString('[3J', $visiblePane,
                'Escape [3J must not leak into visible pane');

            $this->saveAnsiSnapshot($pane, 'resume-step2-resumed');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'resume-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    // ── Canonical fixture ────────────────────────────────────────────────────

    /**
     * Write a custom events.jsonl that simulates a session with:
     *
     *  Turn 1 (completed):
     *    - llm_step_completed with details.thinking + text
     *      → projector must reconstruct thinking + text blocks
     *
     *  Turn 2 (completed with tool):
     *    - llm_step_completed with tool_calls (read tool)
     *      → projector must reconstruct tool-call block
     *    - tool_execution_start → creates ToolResult "Running…"
     *    - tool_call_result_received → (no result text in canonical events)
     *    - tool_execution_end without result
     *      → projector must replace "Running…" with tool name
     *
     *  Turn 3 (cancelled):
     *    - tool_execution_start → creates ToolResult "Running…"
     *    - llm_step_aborted → turn.cancelled
     *      → projector must remove streaming blocks (565d9a9c7 fix)
     *
     * The fixture uses the same canonical format as real sessions
     * (assistant_message with details, tool_calls, etc.) so it exercises
     * the full RuntimeEventTranslator→TranscriptProjector pipeline.
     */
    private function writeCanonicalFixture(string $sessionId): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';

        $events = [];

        // Turn 0: run_started
        $events[] = [
            'schema_version' => '1.0',
            'run_id' => $sessionId,
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Tell me about testing.']]],
                    ],
                ],
            ],
            'ts' => $now,
        ];

        // ── Turn 1: thinking + text (canonical reconstruction of thinking block) ──
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
                'stop_reason' => 'stop',
                'text' => 'Here is the answer you requested.',
                'tool_calls_count' => 0,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Here is the answer you requested.']],
                    'details' => [
                        'thinking' => 'Let me think about this request carefully.',
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 6, 'total_tokens' => 16],
            ],
            'ts' => $now,
        ];

        // ── Turn 2: tool-call + tool execution (no result) ──
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 5, 'turn_no' => 2, 'type' => 'turn_advanced',
            'payload' => ['step_id' => 'turn-2', 'turn_no' => 2, 'parent_turn_no' => null],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 6, 'turn_no' => 2, 'type' => 'leaf_set',
            'payload' => ['turn_no' => 2, 'previous_turn_no' => 1, 'parent_turn_no' => null, 'reason' => 'continue'],
            'ts' => $now,
        ];
        // llm_step_completed with tool_calls + thinking
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 7, 'turn_no' => 2, 'type' => 'llm_step_completed',
            'payload' => [
                'step_id' => 'turn-2',
                'stop_reason' => 'tool_call',
                'text' => null,
                'tool_calls_count' => 1,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'call_read_e2e_001',
                            'name' => 'read',
                            'arguments' => ['path' => '/tmp/example.txt'],
                            'order_index' => 0,
                        ],
                    ],
                    'details' => [
                        'thinking' => 'I need to read the file the user mentioned.',
                    ],
                ],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70],
            ],
            'ts' => $now,
        ];
        // Tool execution sequence
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 8, 'turn_no' => 2, 'type' => 'tool_execution_start',
            'payload' => [
                'tool_call_id' => 'call_read_e2e_001',
                'tool_name' => 'read',
                'order_index' => 0,
                'mode' => 'sequential',
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 9, 'turn_no' => 2, 'type' => 'tool_call_result_received',
            'payload' => [
                'tool_call_id' => 'call_read_e2e_001',
                'order_index' => 0,
                'is_error' => false,
            ],
            'ts' => $now,
        ];
        // tool_execution_end WITHOUT result (real canonical events often lack it)
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 10, 'turn_no' => 2, 'type' => 'tool_execution_end',
            'payload' => [
                'tool_call_id' => 'call_read_e2e_001',
                'order_index' => 0,
                'is_error' => false,
            ],
            'ts' => $now,
        ];

        // ── Turn 3: cancelled (streaming removal) ──
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 11, 'turn_no' => 3, 'type' => 'turn_advanced',
            'payload' => ['step_id' => 'turn-3', 'turn_no' => 3, 'parent_turn_no' => null],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 12, 'turn_no' => 3, 'type' => 'leaf_set',
            'payload' => ['turn_no' => 3, 'previous_turn_no' => 2, 'parent_turn_no' => null, 'reason' => 'continue'],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 13, 'turn_no' => 3, 'type' => 'tool_execution_start',
            'payload' => [
                'tool_call_id' => 'call_cancel_e2e',
                'tool_name' => 'bash',
                'order_index' => 0,
                'mode' => 'sequential',
            ],
            'ts' => $now,
        ];
        $events[] = [
            'schema_version' => '1.0', 'run_id' => $sessionId,
            'seq' => 14, 'turn_no' => 3, 'type' => 'llm_step_aborted',
            'payload' => [
                'step_id' => 'turn-3',
                'stop_reason' => 'aborted',
                'usage' => [],
            ],
            'ts' => $now,
        ];

        $jsonl = '';
        foreach ($events as $ev) {
            $jsonl .= json_encode($ev, \JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($eventsPath, $jsonl);
    }

    // ── Setup ─────────────────────────────────────────────────────

    /**
     * Prove that /resume (no session-id) opens the interactive session
     * picker cleanly — no flicker, no scrollback artifacts, no raw
     * escape-sequence leakage in the visible pane.
     */
    public function testResumeSessionPickerRendersCleanly(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-picker',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── Phase 1: Startup layout ──
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(200_000);

            // ── Phase 2: Create a session so the picker list is non-empty ──
            $this->tmux->sendLiteral($pane, 'hi');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for assistant block to appear.
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: 5.0,
                message: 'Neither ◇ assistant block nor ✕ error appeared after first submit',
                history: 2000,
            );

            // Wait for turn to complete.
            try {
                $this->tmux->waitForCallback(
                    $pane,
                    static fn (string $cap): bool => str_contains($cap, '◇')
                        && !str_contains($cap, '◐ Working...'),
                    timeout: 3.0,
                    message: 'Turn did not complete before picker test',
                    history: 2000,
                );
            } catch (\RuntimeException) {
                // Non-fatal.
            }

            $this->saveAnsiSnapshot($pane, 'picker-step1-session-ready');

            // ── Phase 3: /resume (no id) — open session picker ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/resume');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the picker header text to appear.
            $this->tmux->waitForCaptureContains(
                $pane,
                'Resume session',
                3.0,
            );
            usleep(200_000);

            $this->saveAnsiSnapshot($pane, 'picker-step2-picker-open');

            // ── Phase 4: Assert clean visible pane with picker ──
            $visiblePane = $this->tmux->capturePlain($pane);

            // A) Structural TUI layout must be present.
            self::assertStringContainsString('█', $visiblePane,
                'Hatfield logo (header) must be visible when picker is open');
            self::assertStringContainsString('◆', $visiblePane,
                'Footer must be visible when picker is open');

            // B) Picker header must be visible.
            self::assertStringContainsString('Resume session', $visiblePane,
                'Picker header text must be in the visible pane');

            // C) Session entry must appear in the picker list.
            self::assertStringContainsString('#', $visiblePane,
                'Session entries (marked with #) must appear in the picker list');

            // D) No raw escape-sequence leakage.
            self::assertStringNotContainsString('[2J', $visiblePane,
                'Escape [2J must not leak into visible pane with picker open');
            self::assertStringNotContainsString('[3J', $visiblePane,
                'Escape [3J must not leak into visible pane with picker open');
            self::assertStringNotContainsString('[H', $visiblePane,
                'Escape [H must not leak into visible pane with picker open');

            // E) No stale "Running…" artifacts.
            $runningCount = \substr_count($visiblePane, '● Running…');
            self::assertSame(0, $runningCount,
                \sprintf(
                    'Zero "● Running…" expected when picker is open (found %d)',
                    $runningCount,
                ));

            // ── Phase 5: Close picker with Escape ──
            $this->tmux->sendKey($pane, 'Escape');
            usleep(200_000);

            $this->saveAnsiSnapshot($pane, 'picker-step3-picker-closed');

            $closedPane = $this->tmux->capturePlain($pane);

            // Picker header should no longer be in the visible pane.
            self::assertStringNotContainsString('arrows move, Enter resumes', $closedPane,
                'Picker instruction text must not remain after closing with Esc');

            // Structural layout still present.
            self::assertStringContainsString('█', $closedPane,
                'Header must remain visible after closing picker');

            // Clean exit.
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'picker-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        if (!\is_file($fixturePath)) {
            self::fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $dbPath = 'app_test-tui-resume-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test '
                .'HATFIELD_TEST_DATABASE_PATH=%s '
                .'HOME=%s '
                .'HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s '
                .'%s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash '
                .'2>&1',
            \escapeshellarg($dbPath),
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($fixturePath),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e');
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
        \file_put_contents(
            \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts),
            $ansi,
        );
    }
}
