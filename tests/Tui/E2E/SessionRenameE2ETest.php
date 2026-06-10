<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * E2E tests for /rename command, session-id completions, and
 * rename picker insertion.
 *
 * Uses --prompt to seed session 1 in the DB during startup.
 * The --prompt auto-submits text which triggers createSession();
 * no LLM response is waited on — the rename tests only need the
 * session row to exist.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class SessionRenameE2ETest extends TestCase
{
    private TmuxHarness $tmux;
    private string $snapshotDir;
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
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
    }

    /**
     * Sanity: direct /rename with explicit session id and name.
     * Proves the handler, validation, and DB update work end-to-end.
     */
    public function testDirectRename(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(prompt: 'x'),
            prefix: 'hatfield-rename-direct',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);

            $this->tmux->sendLiteral($pane, '/rename 1 My Name');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCaptureContains($pane, 'renamed to', 10.0);
            self::assertStringContainsString('My Name', $capture);

            $this->saveAnsiSnapshot($pane, 'rename-direct');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->saveAnsiSnapshot($pane, 'rename-direct-FAILURE'); } catch (\Throwable) {}
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    /**
     * Completion: Tab inserts session id, then type name and submit.
     *
     * Session 1 already exists (seeded by --prompt).  Tmux capture
     * strips trailing whitespace, so the trailing space after the
     * inserted session id is invisible but functionally present —
     * the typed name becomes a separate argument.
     */
    public function testRenameCompletionInsertionAndSubmit(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(prompt: 'x'),
            prefix: 'hatfield-rename-completion',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);

            $this->tmux->sendLiteral($pane, '/rename ');
            $this->tmux->sendKey($pane, 'Tab');
            $this->tmux->sendKey($pane, 'Tab');
            $this->tmux->sendLiteral($pane, 'My Renamed Session');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCaptureContains($pane, 'renamed to', 15.0);
            self::assertStringContainsString('My Renamed Session', $capture);

            $this->saveAnsiSnapshot($pane, 'rename-completion');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->saveAnsiSnapshot($pane, 'rename-completion-FAILURE'); } catch (\Throwable) {}
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    /**
     * Picker: /rename + Enter opens picker, select inserts command,
     * type name and submit.
     *
     * Tmux capture strips trailing whitespace — the trailing space
     * after the session id is real in the editor, just invisible in
     * the captured text.
     */
    public function testRenamePickerInsertionAndSubmit(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(prompt: 'x'),
            prefix: 'hatfield-rename-picker',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', 10.0);

            $this->tmux->sendLiteral($pane, '/rename');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains($pane, 'Rename session', 5.0);

            $this->tmux->sendKey($pane, 'Enter');

            // Check for /rename 1 without trailing space — tmux
            // capture-pane -p strips trailing whitespace.  The space
            // IS there (inserted by replaceText), and the typed name
            // below becomes a separate argument.
            $this->tmux->waitForCaptureContains($pane, '/rename 1', 5.0);

            $this->tmux->sendLiteral($pane, 'Picker Renamed');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCaptureContains($pane, 'renamed to', 15.0);
            self::assertStringContainsString('Picker Renamed', $capture);

            $this->saveAnsiSnapshot($pane, 'rename-picker');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->dumpArtifacts($pane, $e->getMessage());
            try { $this->saveAnsiSnapshot($pane, 'rename-picker-FAILURE'); } catch (\Throwable) {}
            try { $this->tmux->sendKey($pane, 'C-d'); } catch (\Throwable) {}
            throw $e;
        }
    }

    private function agentCommand(string $prompt = ''): string
    {
        [$php, $script] = AgentTestExecutable::command();

        $promptArg = '' !== $prompt
            ? \sprintf(' --prompt=%s', \escapeshellarg($prompt))
            : '';

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash%s 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
            $promptArg,
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf('%s/var/tmp/tui-e2e-%s', $this->projectRoot, \bin2hex(\random_bytes(6)));
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
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
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
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
                        'allow_command_patterns' => [],
                        'allow_write_outside_cwd' => [],
                        'protected_read_patterns' => [],
                        'dangerous_command_patterns' => [],
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
        $ts = \date('Ymd-His');
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts);
        \file_put_contents($path, $ansi);
    }

    private function dumpArtifacts(TmuxPane $pane, string $context): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $plain = $this->tmux->capturePlain($pane);
        $paneExists = $this->tmux->paneExists($pane) ? 'yes' : 'no';

        \fwrite(\STDERR, "\n\n=== SESSION RENAME E2E FAILURE ===\n");
        \fwrite(\STDERR, "Context: {$context}\n");
        \fwrite(\STDERR, "Pane exists: {$paneExists}\n");
        \fwrite(\STDERR, "Test CWD: {$this->testProjectDir}\n\n");

        $ts = \date('Ymd-His');
        $dumpDir = $this->projectRoot . '/var/tmp/tui-failures';
        @\mkdir($dumpDir, 0o777, true);

        \file_put_contents("{$dumpDir}/fail-{$ts}.ansi", $ansi);
        \file_put_contents("{$dumpDir}/fail-{$ts}.txt", $plain);

        \fwrite(\STDERR, "Plain snapshot:\n{$plain}\n\n");
        \fwrite(\STDERR, "=== END SESSION RENAME E2E FAILURE ===\n\n");
    }
}
