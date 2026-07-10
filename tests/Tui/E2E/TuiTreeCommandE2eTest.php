<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for the /tree rewindable turn tree picker.
 *
 * Tests that typing /tree in a session with at least one turn opens
 * the picker overlay showing the turn tree with a rewindable entry,
 * and that Escape closes the picker without mutating session state.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiTreeCommandE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
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

    public function testTreeCommandShowsTurnOverlayAndEscapeCloses(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-tree-smoke',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── 1. Wait for TUI startup ──
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            // ── 2. Send a prompt to create a turn ──
            $this->tmux->sendLiteral($pane, 'hello');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the assistant response block (◇) and exact replay fixture text.
            $assistantCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇')
                    && str_contains($cap, 'Follow-up acknowledged.'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Assistant response block with fixture text did not appear',
                history: 2000,
            );
            $this->assertStringContainsString(
                'Follow-up acknowledged.',
                $assistantCapture,
                'Replay fixture response text missing from transcript output',
            );

            // ── 3. Send /tree command ──
            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/tree');
            $this->tmux->sendKey($pane, 'Enter');

            // Wait for the tree overlay to appear — look for turn entry and rewind header
            $treeCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Session turn tree') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: 'Tree picker overlay did not appear with turn entry and rewind header',
                history: 2000,
            );

            $this->assertTrue(
                str_contains($treeCapture, 'user:') || str_contains($treeCapture, 'assistant:'),
                'Tree picker should show a role-prefixed turn row in the capture.'
            );

            $this->assertStringContainsString('rewind', $treeCapture,
                'Tree picker header should indicate rewind mode.'
            );

            $this->saveAnsiSnapshot($pane, 'tree-picker-open');

            // ── 4. Send Escape to close the picker ──
            $this->tmux->sendKey($pane, 'Escape');

            $postCloseCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '● idle')
                    && !str_contains($cap, 'Session turn tree')
                    && str_contains($cap, '◆'),
                timeout: 5.0,
                message: 'Tree picker did not close with idle footer',
                history: 500,
            );

            $this->assertStringContainsString('● idle', $postCloseCapture,
                'Session should remain in idle state after closing tree picker');
            $this->assertStringContainsString('◆', $postCloseCapture,
                'Footer model indicator should still be present after closing tree picker');

            $this->saveAnsiSnapshot($pane, 'tree-picker-closed-idle');

            // ── 5. Clean exit ──
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'tree-picker-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    public function testTreeEnterRewindsTranscriptToEarlierTurn(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandForFixtureChain(
                'tui-tree-rewind-turn1-07c.json',
                'tui-tree-rewind-turn2-07c.json',
            ),
            prefix: 'tui-tree-rewind-07c',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->submitPrompt($pane, 'first-turn-marker-07c');
            $this->waitAssistantBlock($pane);
            $this->tmux->waitForCaptureContains($pane, 'FIRST_TURN_REPLY_07C', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL);

            $this->submitPrompt($pane, 'second-turn-marker-07c');
            $this->waitAssistantBlock($pane);
            $this->tmux->waitForCaptureContains($pane, 'SECOND_TURN_REPLY_07C', TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL);

            $this->runSlashCommand($pane, '/tree');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Session turn tree') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: 'Tree picker overlay did not open',
                history: 2000,
            );

            // Open on current leaf (latest turn). One Up selects the first turn in a linear two-turn tree.
            $this->tmux->sendKey($pane, 'Up');
            usleep(200_000);

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'first-turn-marker-07c'),
                timeout: 5.0,
                message: 'Tree picker selection should highlight the first-turn row',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '● idle')
                    && !str_contains($cap, 'Session turn tree'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Tree picker did not close after Enter rewind',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'first-turn-marker-07c')
                    && str_contains($cap, 'FIRST_TURN_REPLY_07C')
                    && !str_contains($cap, 'second-turn-marker-07c')
                    && !str_contains($cap, 'SECOND_TURN_REPLY_07C'),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Transcript did not rewind to first-turn leaf (second-turn content still visible)',
                history: 500,
            );

            $paneCapture = $this->tmux->capturePlain($pane);
            $this->assertStringContainsString('first-turn-marker-07c', $paneCapture,
                'Rewound transcript should still show the first-turn user marker in the current pane.');
            $this->assertStringContainsString('FIRST_TURN_REPLY_07C', $paneCapture,
                'Rewound transcript should still show the first-turn assistant reply in the current pane.');
            $this->assertStringNotContainsString('second-turn-marker-07c', $paneCapture,
                'Abandoned second-turn user marker must disappear from the current pane after rewind.');
            $this->assertStringNotContainsString('SECOND_TURN_REPLY_07C', $paneCapture,
                'Abandoned second-turn assistant reply must disappear from the current pane after rewind.');

            $this->saveAnsiSnapshot($pane, 'tree-enter-rewind-07c');

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'tree-enter-rewind-07c-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-followup-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tree-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function submitPrompt(TmuxPane $pane, string $text): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        usleep(50_000);
        $this->tmux->sendLiteral($pane, $text);
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function runSlashCommand(TmuxPane $pane, string $command): void
    {
        $this->tmux->sendKey($pane, 'C-u');
        usleep(50_000);
        $this->tmux->sendLiteral($pane, $command);
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function waitAssistantBlock(TmuxPane $pane): void
    {
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, '◇'),
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block (◇) did not appear',
            history: 2000,
        );
    }

    private function agentCommandForFixtureChain(string ...$fixtureFiles): string
    {
        $paths = [];
        foreach ($fixtureFiles as $file) {
            $path = $this->projectRoot.'/tests/Tui/E2E/fixtures/'.$file;
            if (is_file($path)) {
                $paths[] = $path;
            }
        }
        $fixtureEnv = [] !== $paths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg(implode(';', $paths)).' '
            : '';

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-tree-rewind-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-tree');
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
                                    'off' => '0', 'minimal' => '0', 'low' => '0', 'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
            'extensions' => [
                'enabled' => ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
                'settings' => [
                    'safe_guard' => [
                        'tool_names' => ['bash' => 'bash', 'write' => 'write', 'edit' => 'edit', 'read' => 'read'],
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
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts), $ansi);
    }
}
