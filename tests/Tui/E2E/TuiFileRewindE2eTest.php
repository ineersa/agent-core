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
            self::markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
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
            self::assertStringContainsString('after', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->openRewindTurnPicker($pane);
            $this->selectRewindTurnWithCheckpoint($pane, 1);
            $restoreCapture = $this->confirmRestoreFilesToSelectedTurn($pane);
            self::assertStringContainsString('Action completed', $restoreCapture);
            self::assertStringNotContainsString('File rewind failed', $restoreCapture);
            self::assertStringContainsString('before', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->runSlashCommand($pane, '/rewind');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'File rewind'),
                timeout: 10.0,
                message: '/rewind turn picker did not reopen for undo',
                history: 2000,
            );
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Undo last file restore'),
                timeout: 10.0,
                message: 'Undo action menu did not appear',
                history: 2000,
            );
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Action completed') || str_contains($cap, 'File rewind failed'),
                timeout: 15.0,
                message: 'Undo action did not complete',
                history: 2000,
            );
            self::assertStringContainsString('after', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->submitPrompt($pane, 'follow-up check');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);

            $this->runSlashCommand($pane, '/tree');
            $treeCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Turn 1:') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: '/tree conversation picker did not appear',
                history: 2000,
            );
            self::assertStringNotContainsString('Restore files to this turn', $treeCapture);
            self::assertStringNotContainsString('Undo last file restore', $treeCapture);
            self::assertStringNotContainsString('File rewind', $treeCapture);

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

            self::assertStringContainsString(
                'after',
                (string) file_get_contents($this->testProjectDir.'/target.txt'),
                'Edit tool must change target.txt from before to after',
            );

            $this->openRewindTurnPicker($pane);
            $this->selectRewindTurnWithCheckpoint($pane, 1);
            $restoreCapture = $this->confirmRestoreFilesToSelectedTurn($pane);
            self::assertStringContainsString('Action completed', $restoreCapture);
            self::assertStringNotContainsString('File rewind failed', $restoreCapture);
            self::assertStringContainsString('before', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->runSlashCommand($pane, '/rewind');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'File rewind'),
                timeout: 10.0,
                message: '/rewind did not reopen for undo after edit restore',
                history: 2000,
            );
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Undo last file restore'),
                timeout: 10.0,
                message: 'Undo menu did not appear',
                history: 2000,
            );
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Down');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Action completed') || str_contains($cap, 'File rewind failed'),
                timeout: 15.0,
                message: 'Undo after edit restore did not complete',
                history: 2000,
            );
            self::assertStringContainsString('after', (string) file_get_contents($this->testProjectDir.'/target.txt'));

            $this->submitPrompt($pane, 'follow-up after edit rewind');
            $this->waitAssistantBlock($pane);
            $this->assertNotStuckWorking($pane);

            $this->runSlashCommand($pane, '/tree');
            $treeCapture = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'Turn 1:') && str_contains($cap, 'rewind'),
                timeout: 10.0,
                message: '/tree conversation picker did not appear after edit journey',
                history: 2000,
            );
            self::assertStringNotContainsString('Restore files to this turn', $treeCapture);
            self::assertStringNotContainsString('Undo last file restore', $treeCapture);
            self::assertStringNotContainsString('File rewind', $treeCapture);
            self::assertSame(1, substr_count($treeCapture, 'Turn 1:'), 'Tree picker should list Turn 1 once (no duplicate-turn regression in overlay capture)');
            self::assertLessThanOrEqual(1, substr_count($treeCapture, 'Turn 2:'), 'Tree picker should not duplicate Turn 2 entries in overlay capture');

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

    private function waitForTurnCheckpointRecorded(int $turnNo, float $timeoutSeconds = 20.0): void
    {
        $ledgerPath = $this->ledgerPath();
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($ledgerPath)) {
                $decoded = json_decode((string) file_get_contents($ledgerPath), true);
                if (is_array($decoded)) {
                    foreach ($decoded['checkpoints'] ?? [] as $checkpoint) {
                        if (!is_array($checkpoint)) {
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
        self::fail('Timed out waiting for file rewind checkpoint for turn '.$turnNo.' at '.$ledgerPath);
    }

    private function openRewindTurnPicker(TmuxPane $pane): void
    {
        $this->runSlashCommand($pane, '/rewind');
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'File rewind'),
            timeout: 10.0,
            message: '/rewind did not open file rewind turn picker',
            history: 2000,
        );
    }

    private function selectRewindTurnWithCheckpoint(TmuxPane $pane, int $turnNo): void
    {
        $noCheckpoint = 'Turn '.$turnNo.': no file checkpoint';
        $hasPreview = static fn (string $cap): bool => str_contains($cap, 'Turn '.$turnNo.':')
            && !str_contains($cap, $noCheckpoint)
            && (
                str_contains($cap, 'Turn '.$turnNo.': modified')
                || str_contains($cap, 'Turn '.$turnNo.': added')
                || str_contains($cap, 'Turn '.$turnNo.': deleted')
                || str_contains($cap, 'Turn '.$turnNo.': preview unavailable')
            );

        for ($nav = 0; $nav < 14; ++$nav) {
            $cap = $this->tmux->capturePlainWithHistory($pane, 1200);
            if ($hasPreview($cap)) {
                break;
            }
            $this->tmux->sendKey($pane, 'Up');
            usleep(150_000);
        }

        $this->tmux->waitForCallback(
            $pane,
            $hasPreview,
            timeout: 8.0,
            message: 'Did not select turn '.$turnNo.' with a file checkpoint in /rewind picker',
            history: 2000,
        );
        $this->tmux->sendKey($pane, 'Enter');
    }

    private function confirmRestoreFilesToSelectedTurn(TmuxPane $pane): string
    {
        $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Restore files to this turn'),
            timeout: 10.0,
            message: 'File rewind action menu did not appear',
            history: 2000,
        );
        $this->tmux->sendKey($pane, 'Enter');

        return $this->tmux->waitForCallback(
            $pane,
            static fn (string $cap): bool => str_contains($cap, 'Action completed') || str_contains($cap, 'File rewind failed'),
            timeout: 15.0,
            message: 'Restore action did not complete',
            history: 2000,
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
            if (\is_file($path)) {
                $paths[] = $path;
            }
        }
        $fixtureEnv = [] !== $paths
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.\escapeshellarg(\implode(';', $paths)).' '
            : '';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            \escapeshellarg('app_test-tui-file-rewind-'.bin2hex(random_bytes(4)).'.sqlite'),
            \escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-file-rewind');
        @\mkdir($dir.'/.hatfield', 0o777, true);
        \file_put_contents($dir.'/target.txt', "before\n");

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
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\FileRewind\\FileRewindExtension',
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
        \file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);
        \file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        \file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts), $ansi);
    }
}
