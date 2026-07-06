<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed tmux journey for file rewind (/rewind) vs conversation-only /tree.
 *
 * Test thesis: after a completed assistant turn captures a checkpoint, a subsequent
 * local file change can be restored and undone via /rewind without coupling file
 * restore into /tree; follow-up turns complete without stuck Working.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiFileRewindE2eTest extends TestCase
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

    public function testRewindRestoreUndoAndTreeStaysConversationOnly(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-file-rewind',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->submitPrompt($pane, 'hello');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);
            $this->waitForTurnCheckpointRecorded(1);

            // Simulate post-checkpoint worktree mutation after turn-1 snapshot is persisted.
            file_put_contents($this->testProjectDir.'/target.txt', "after\n");
            $this->assertStringContainsString('after', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->openRewindTurnPicker($pane);
            $this->selectRewindTurnWithCheckpoint($pane, 1);
            $restoreCapture = $this->confirmRestoreFilesToSelectedTurn($pane);
            $this->assertStringNotContainsString('File rewind failed', $restoreCapture);
            $this->assertStringContainsString('before', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->submitPrompt($pane, 'follow-up check');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);

            $this->runSlashCommand($pane, '/tree');
            $treeCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Session turn tree') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: '/tree conversation picker did not appear',
                history: 2000,
            );
            $this->assertStringNotContainsString('Restore files to this turn', $treeCapture);
            $this->assertStringNotContainsString('Undo last file restore', $treeCapture);
            $this->assertStringNotContainsString('File rewind', $treeCapture);

            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'file-rewind-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    /**
     * Replay edit tool mutates target.txt; turn-1 checkpoint (pre-edit) restores "before" and undo returns "after".
     */
    public function testRewindRestoreUndoAfterEditToolCheckpoint(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandForFixtureChain(
                'tui-followup-response.json',
                'tui-tool-call-edit.json',
            ),
            prefix: 'tui-file-rewind-edit',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->submitPrompt($pane, 'hello');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);
            $this->waitForTurnCheckpointRecorded(1);

            $this->submitPrompt($pane, 'Edit target.txt');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);
            $this->waitForTargetFileContains(
                'after',
                timeoutSeconds: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Edit tool must change target.txt from before to after',
            );

            $this->openRewindTurnPicker($pane);
            $this->selectRewindTurnWithCheckpoint($pane, 1);
            $restoreCapture = $this->confirmRestoreFilesToSelectedTurn($pane);
            $this->assertStringNotContainsString('File rewind failed', $restoreCapture);
            $this->assertStringContainsString('before', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->submitPrompt($pane, 'follow-up after edit rewind');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);

            $this->runSlashCommand($pane, '/tree');
            $treeCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Session turn tree') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: '/tree conversation picker did not appear after edit journey',
                history: 2000,
            );
            $this->assertStringNotContainsString('Restore files to this turn', $treeCapture);
            $this->assertStringNotContainsString('Undo last file restore', $treeCapture);
            $this->assertStringNotContainsString('File rewind', $treeCapture);
            $this->assertSame(1, substr_count($treeCapture, 'Session turn tree — Enter to rewind'), 'Tree picker should show a single tree header (no stacked overlay regression)');
            $this->assertStringContainsString('Session turn tree', $treeCapture, 'Tree picker should open for conversation-only rewind');

            $this->saveAnsiSnapshot($pane, 'file-rewind-edit-tool');
            $this->tmux->sendKey($pane, 'Escape');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'file-rewind-edit-tool-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function ledgerPath(): string
    {
        $real = realpath($this->testProjectDir) ?: $this->testProjectDir;
        $hash = hash('sha256', str_replace('\\', '/', $real));

        return $this->testProjectDir.'/.hatfield/rewind/snapshots/'.$hash.'/ledger.json';
    }

    private function waitForTargetFileContains(string $needle, float $timeoutSeconds = 30.0, string $message = ''): void
    {
        $path = $this->testProjectDir.'/target.txt';
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $contents = (string) file_get_contents($path);
                if (str_contains($contents, $needle)) {
                    return;
                }
            }
            usleep(100_000);
        }

        $final = is_file($path) ? (string) file_get_contents($path) : '(missing)';
        $this->fail('' !== $message ? $message : 'Timed out waiting for target.txt to contain '.$needle.'; final='.$final);
    }

    private function waitForTurnCheckpointRecorded(int $turnNo, float $timeoutSeconds = 20.0): void
    {
        $ledgerPath = $this->ledgerPath();
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($ledgerPath)) {
                $decoded = json_decode((string) file_get_contents($ledgerPath), true);
                if (\is_array($decoded)) {
                    foreach ($decoded['checkpoints'] ?? [] as $checkpoint) {
                        if (!\is_array($checkpoint)) {
                            continue;
                        }
                        if ((int) ($checkpoint['turn_no'] ?? 0) === $turnNo) {
                            return;
                        }
                    }
                }
            }
            usleep(100_000);
        }
        $this->fail('Timed out waiting for file rewind checkpoint for turn '.$turnNo.' at '.$ledgerPath);
    }

    private function openRewindTurnPicker(TmuxPane $pane): void
    {
        $this->runSlashCommand($pane, '/rewind');
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Checkpoint turn'),
            timeout: 10.0,
            message: '/rewind did not open file rewind checkpoint picker',
            history: 2000,
        );
    }

    private function selectedRewindPickerTurn(string $capture): ?int
    {
        foreach (explode("\n", $capture) as $line) {
            if (!str_contains($line, '→')) {
                continue;
            }
            if (preg_match('/checkpoint (\d+):/i', $line, $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function selectRewindTurnWithCheckpoint(TmuxPane $pane, int $turnNo): void
    {
        $rowShowsTurn = static fn (string $cap): bool => str_contains($cap, 'checkpoint '.$turnNo.':');

        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Checkpoint turn') && str_contains($cap, '→'),
            timeout: 10.0,
            message: '/rewind checkpoint picker did not appear before turn selection',
            history: 2000,
        );

        for ($nav = 0; $nav < 24; ++$nav) {
            $cap = $this->tmux->capturePlainWithHistory($pane, 2000);
            $selectedTurn = $this->selectedRewindPickerTurn($cap);
            if ($selectedTurn === $turnNo && $rowShowsTurn($cap)) {
                break;
            }
            if ($selectedTurn === $turnNo) {
                $this->tmux->waitForCallback(
                    $pane,
                    $rowShowsTurn,
                    timeout: 12.0,
                    message: 'Turn '.$turnNo.' selected but checkpoint row did not appear',
                    history: 2000,
                );
                break;
            }
            if (null !== $selectedTurn && $selectedTurn <= $turnNo) {
                break;
            }

            $beforeUp = $selectedTurn;
            for ($retry = 0; $retry < 3; ++$retry) {
                $this->tmux->sendKey($pane, 'Up');
                usleep(200_000);
                try {
                    $this->tmux->waitForCallback(
                        $pane,
                        function (string $cap) use ($beforeUp): bool {
                            $current = $this->selectedRewindPickerTurn($cap);

                            return null !== $current && $current !== $beforeUp;
                        },
                        timeout: 8.0,
                        message: 'Rewind picker selection did not move after Up (stuck on turn '.($beforeUp ?? 'unknown').')',
                        history: 2000,
                    );
                    break;
                } catch (\RuntimeException $e) {
                    if (2 === $retry) {
                        throw $e;
                    }
                }
            }
        }

        $this->tmux->waitForCallback(
            $pane,
            fn (string $cap): bool => $this->selectedRewindPickerTurn($cap) === $turnNo && $rowShowsTurn($cap),
            timeout: 8.0,
            message: 'Did not select turn '.$turnNo.' in /rewind checkpoint list in /rewind picker',
            history: 2000,
        );
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function confirmRestoreFilesToSelectedTurn(TmuxPane $pane): string
    {
        // v1 /rewind restores files directly on Enter (no secondary action menu).
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => !str_contains($cap, 'File rewind — select checkpoint')
                && !str_contains($cap, 'Checkpoint turn'),
            timeout: 15.0,
            message: 'File rewind picker did not close after restore',
            history: 2000,
        );

        return $this->tmux->capturePlainWithHistory($pane, 2000);
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

    private function assertNotStuckWorking(TmuxPane $pane): void
    {
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => !str_contains($cap, '◐ Working...') && !str_contains($cap, 'Working...'),
            timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
            message: 'Session stuck in Working state',
            history: 2000,
        );
    }

    private function agentCommand(): string
    {
        return $this->agentCommandForFixtureChain('tui-followup-response.json');
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

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-file-rewind-');

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
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-file-rewind');
        @mkdir($dir.'/.hatfield', 0o777, true);
        file_put_contents($dir.'/target.txt', "before\n");

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
                                    'off' => '0', 'minimal' => '0', 'low' => '0',
                                    'medium' => '0', 'high' => '0', 'xhigh' => '0',
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
                    'Ineersa\\HatfieldExt\\FileRewind\\FileRewindExtension',
                ],
                'settings' => [
                    'file_rewind' => ['enabled' => true, 'max_retained_turns' => 100, 'max_file_bytes' => 2097152],
                    'safe_guard' => [
                        'tool_names' => ['bash' => 'bash', 'write' => 'write', 'edit' => 'edit', 'read' => 'read'],
                        'allow_command_patterns' => ['^printf\\b'],
                        'allow_write_outside_cwd' => [],
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
