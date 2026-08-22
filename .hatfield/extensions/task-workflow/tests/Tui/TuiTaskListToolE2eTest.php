<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests\Tui;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\TmuxHarness;
use Ineersa\Tui\Tests\E2E\TmuxPane;
use Ineersa\Tui\Tests\E2E\TuiE2eDatabaseEnv;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E proof for the task_list tool output contract: the TUI/controller
 * replay path must render exactly ONE canonical structured task payload
 * (TOON `tasks[]` + `include_archive`) and must NOT duplicate the full
 * task list as a formatted bullet-list `message`.
 *
 * Uses a replay fixture that forces a real `task_list` tool call.  The
 * task-workflow extension is enabled in the isolated project settings and
 * resolves its board from HATFIELD_TASK_WORKFLOW_ROOT (isolated temp
 * board seeded with one TODO task).  A second explicit replay fixture returns "done" after the tool executes.
 *
 * Design (mirrors TuiToolOutputE2eTest):
 *  - Single detached tmux session with a replay fixture returning a
 *    task_list tool_call.
 *  - Asserts the structured task fields (status/file/title) appear in the
 *    transcript exactly once via the TOON payload.
 *  - Asserts the redundant `- TODO/demo.md: ...` bullet-list copy is
 *    absent — it only existed as the tool's `message` field.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiTaskListToolE2eTest extends TestCase
{
    /** Task title seeded on the isolated board; asserted in transcript. */
    private const TASK_TITLE = 'Demo task from E2E';
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $boardRoot;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->boardRoot = $this->testProjectDir.'/board';
        $this->seedTaskBoard($this->boardRoot);
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
     * The task_list tool result must render the structured tasks[] payload
     * and must NOT contain the redundant formatted bullet-list message.
     */
    public function testTaskListRendersStructuredPayloadWithoutRedundantMessage(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-task-list',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, 'List the tasks');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant block (◇) — the tool executed and the
            // post-tool replay fixture returned "done".
            $capture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    || str_contains($cap, '✕'),
                timeout: 20.0,
                message: 'Neither ◇ assistant block nor ✕ error block appeared after task_list tool execution',
                history: 2000,
            );
            $this->assertTrue(
                str_contains($capture, '◇'),
                'Transcript must display an assistant block (◇) after tool execution + post-tool done response',
            );

            $fullCapture = $this->tmux->capturePlainWithHistory($pane, 2000);

            // Tool call card names the task_list tool.
            $this->assertStringContainsString(
                'task_list',
                $fullCapture,
                'Tool call card must render the task_list tool name',
            );

            // Canonical structured payload: task fields from TOON tasks[].
            $this->assertStringContainsString(
                'demo.md',
                $fullCapture,
                'Structured tasks[0].file must be visible in the tool result',
            );
            $this->assertStringContainsString(
                self::TASK_TITLE,
                (string) preg_replace('/\s+/', ' ', $fullCapture),
                'Structured tasks[0].title must be visible in the tool result (tolerating terminal line wrap)',
            );
            $this->assertStringContainsString(
                'include_archive',
                $fullCapture,
                'Structured include_archive field must be visible in the tool result',
            );

            // The redundant formatted bullet-list message must NOT appear.
            // It only existed as the tool's `message` field (duplicated list).
            $this->assertStringNotContainsString(
                '- TODO/demo.md',
                $fullCapture,
                'task_list must not render the redundant formatted bullet-list message copy',
            );

            $this->saveAnsiSnapshot($pane, 'task-list-tool-output');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'task-list-tool-output-FAILURE');
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
        $fixturePaths = [
            __DIR__.'/fixtures/tui-task-list-tool-call.json',
            __DIR__.'/fixtures/tui-task-list-done.json',
        ];
        $existing = array_values(array_filter($fixturePaths, is_file(...)));
        $fixtureEnv = [] !== $existing
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $existing)).' '
            : '';

        $projectDir = ProjectDir::get();
        $php = \PHP_BINARY;
        $script = $projectDir.'/bin/console';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-task-list-');

        $dbPath = $paths['app'];
        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHATFIELD_TASK_WORKFLOW_ROOT=%s %sHOME=%s %s %s agent '
                .'--model=llama_cpp_test/test '
                .'--tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->boardRoot),
            $fixtureEnv,
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-task-list');
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
                    'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension',
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

        $yaml = \Symfony\Component\Yaml\Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);

        @mkdir($dir.'/home/.hatfield', 0o777, true);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    /**
     * Seed a minimal external task board: TODO/demo.md with a unique title.
     */
    private function seedTaskBoard(string $boardRoot): void
    {
        @mkdir($boardRoot.'/TODO', 0o777, true);
        file_put_contents(
            $boardRoot.'/TODO/demo.md',
            '# '.self::TASK_TITLE."\n\n## Goal\nDemo task for E2E.\n",
        );
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        file_put_contents($path, $ansi);
    }
}
