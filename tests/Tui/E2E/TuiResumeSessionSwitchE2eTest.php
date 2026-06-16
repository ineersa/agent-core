<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof that /resume <session-id> produces a clean transcript from
 * canonical events.jsonl replay — no transient streaming widgets
 * (thinking fragments, tool-call placeholders, Running… tool-result
 * rows) resurrected as historical transcript blocks.
 *
 * This test exercises the session-resume projection path end-to-end
 * via TmuxHarness.  A session is initially created with a simple
 * completed turn, then its events.jsonl is replaced with a fixture
 * containing a completed turn followed by a cancelled streaming turn.
 * After /resume the visible pane must show canonical completed history
 * plus a cancellation marker, but NO stale streaming fragments.
 *
 * The projection semantics proven here:
 *  - on turn/run cancellation, active streaming blocks are REMOVED,
 *    not finalized.
 *  - Completed (non-streaming) blocks from prior turns survive.
 *  - Cancellation blocks (non-streaming) are appended.
 *
 * Flow:
 *  1. Start TUI, wait for startup layout.
 *  2. Submit "hi" → session is created, deterministic replay responds.
 *  3. Extract session ID from footer history.
 *  4. Overwrite <session>/events.jsonl with a fixture containing
 *     a completed turn + a cancelled streaming turn.
 *  5. /resume <sessionId> → session replayed from new events.jsonl.
 *  6. Assert current visible pane (capturePlain) has proper layout,
 *     the completed turn's assistant text, a cancellation marker,
 *     and NO transient streaming fragments.
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

    public function testResumeAfterNewRendersCleanVisiblePane(): void
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

            // ── Phase 3: Replace events.jsonl with cancellation fixture ──
            //
            // Overwrite the session's events.jsonl with a fixture that
            // contains a completed first turn followed by a cancelled
            // streaming second turn.  This simulates a session where
            // the user cancelled mid-streaming on turn 2, and the prior
            // fork was projecting the transient streaming blocks as
            // permanent history on resume.
            $this->writeCancellationFixture($sessionId);

            // ── Phase 4: /resume <sessionId> ──
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for header (█) in the VISIBLE PANE — proves re-render.
            $this->tmux->waitForCaptureContains($pane, '█', 5.0);
            usleep(300_000);

            // ── Phase 5: Assert clean current visible-pane layout ──
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

            // B) Session ID must appear in the visible pane.
            self::assertStringContainsString($sessionId, $visiblePane,
                'Session ID must appear in the current pane after /resume');

            // C) The completed first turn's assistant text must survive replay.
            self::assertStringContainsString('Hello from the test harness.', $visiblePane,
                'Completed assistant text from turn 1 must survive /resume replay');

            // D) Cancellation marker must appear (the turn 2 cancellation block).
            self::assertStringContainsString('turn cancelled', $visiblePane,
                'Cancellation block must be visible after /resume replay');

            // E) No orphaned streaming fragments — these are the core
            //    assertions for the projection fix.
            //
            //    The fixture's turn 2 contains a tool_execution.start
            //    (streaming ToolResult "Running…") followed by an
            //    llm_step_aborted (turn.cancelled).  The projection
            //    fix removes streaming blocks on cancellation instead
            //    of finalizing them, so "● Running…" must NOT appear
            //    in the current visible pane.

            self::assertStringNotContainsString('◇ </think>', $visiblePane,
                'Transient thinking close tags must not appear in the current pane after /resume');

            $runningCount = \substr_count($visiblePane, '● Running…');
            self::assertSame(0, $runningCount,
                \sprintf(
                    'Zero "● Running…" expected in current pane after /resume (found %d). '
                    .'Removing streaming blocks on cancellation must prevent transient '
                    .'tool-result rows from being resurrected as historical content.',
                    $runningCount,
                ));

            // F) No raw escape-sequence leakage.
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

    // ── Cancellation fixture ────────────────────────────────────────────────

    /**
     * Write a custom events.jsonl that simulates a session with:
     *  - Turn 1: completed assistant message (non-streaming, survives)
     *  - Turn 2: tool_execution started (streaming Running…) then
     *            llm_step_aborted (turn.cancelled — removes streaming blocks)
     *
     * After fix, the projector removes the streaming ToolResult block
     * on turn.cancelled so the visible pane shows only the completed
     * assistant text + the cancellation marker.
     */
    private function writeCancellationFixture(string $sessionId): void
    {
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';

        // Build the fixture event by event.
        $events = [];

        // Turn 0: run_started
        $events[] = [
            'schema_version' => '1.0',
            'run_id' => $sessionId,
            'seq' => 1,
            'turn_no' => 0,
            'type' => 'run_started',
            'payload' => [
                'step_id' => 'start-fix-1',
                'payload' => [
                    'system_prompt' => '',
                    'messages' => [
                        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi']]],
                    ],
                ],
            ],
            'ts' => $now,
        ];

        // Turn 1: advance + leaf_set + completed message
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>2,'turn_no'=>1,'type'=>'turn_advanced','payload'=>['step_id'=>'followup-1','turn_no'=>1,'parent_turn_no'=>null],'ts'=>$now];
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>3,'turn_no'=>1,'type'=>'leaf_set','payload'=>['turn_no'=>1,'previous_turn_no'=>null,'parent_turn_no'=>null,'reason'=>'continue'],'ts'=>$now];
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>4,'turn_no'=>1,'type'=>'llm_step_completed','payload'=>['step_id'=>'followup-1','stop_reason'=>'stop','text'=>'Hello from the test harness.','usage'=>['input_tokens'=>10,'output_tokens'=>6,'total_tokens'=>16],'tool_calls_count'=>0],'ts'=>$now];

        // Turn 2: advance + leaf_set + tool_execution_start (streaming, creates Running…) + llm_step_aborted (cancellation)
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>5,'turn_no'=>2,'type'=>'turn_advanced','payload'=>['step_id'=>'followup-2','turn_no'=>2,'parent_turn_no'=>null],'ts'=>$now];
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>6,'turn_no'=>2,'type'=>'leaf_set','payload'=>['turn_no'=>2,'previous_turn_no'=>1,'parent_turn_no'=>null,'reason'=>'continue'],'ts'=>$now];
        // tool_execution_start → creates streaming ToolResult "Running…" block
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>7,'turn_no'=>2,'type'=>'tool_execution_start','payload'=>['tool_call_id'=>'call_cancel_test','tool_name'=>'bash','order_index'=>0,'mode'=>'sequential'],'ts'=>$now];
        // llm_step_aborted → turn.cancelled → removeActiveStreamingBlocks
        $events[] = ['schema_version'=>'1.0','run_id'=>$sessionId,'seq'=>8,'turn_no'=>2,'type'=>'llm_step_aborted','payload'=>['step_id'=>'followup-2','stop_reason'=>'aborted','usage'=>[]],'ts'=>$now];

        $jsonl = '';
        foreach ($events as $ev) {
            $jsonl .= json_encode($ev, \JSON_THROW_ON_ERROR)."\n";
        }

        file_put_contents($eventsPath, $jsonl);
    }

    // ── Setup ─────────────────────────────────────────────────────

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
