<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * End-to-end proof for GitHub issue #123: multiline @ file completion
 * must preserve preceding editor content instead of clearing the editor.
 *
 * Uses TmuxHarness for interactive TUI testing on the real test LLM
 * endpoint.  Does NOT submit a prompt or wait for LLM responses — all
 * assertions are visual/pane-capture based.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class FileCompletionMultilineE2ETest extends TestCase
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
        $this->projectRoot = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
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

    /**
     * @test
     *
     * Types a multiline editor content with an @ file completion
     * trigger, accepts the first suggestion via Tab, and asserts
     * the preceding lines are preserved and the completed path
     * appears in the editor.
     *
     * This reproduces and fixes GitHub issue #123 where Tab on
     * "Hello\n\n@" would clear the editor instead of inserting the
     * file reference.
     */
    public function testFileCompletionPreservesMultilineContent(): void
    {
        // ── 1. Create test files for the file mention index ───────
        // The index builder scans CWD at startup.  Create a few files
        // with distinctive names under a known directory so we can
        // assert specific completion entries.
        @\mkdir($this->testProjectDir.'/testfiles', 0o777, true);
        \file_put_contents(
            $this->testProjectDir.'/testfiles/alpha.txt',
            'test content',
        );
        \file_put_contents(
            $this->testProjectDir.'/testfiles/baker.txt',
            'test content',
        );

        // ── 2. Start the agent TUI ────────────────────────────────
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'file-completion-multiline',
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // ── 3. Wait for TUI to render ─────────────────────────
            $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: '█',   // Hatfield logo
                timeout: 5.0,
            );

            // Give the index builder a moment to finish.
            \usleep(500_000);

            // ── 4. Type multiline content with @ trigger ──────────
            // Line 1: "Hello"
            $this->tmux->sendLiteral($pane, 'Hello');

            // Insert newlines with Ctrl+J (tmux name: C-j).
            $this->tmux->sendKey($pane, 'C-j');
            $this->tmux->sendKey($pane, 'C-j');

            // Line 3: "@test" — enough to narrow completion.
            $this->tmux->sendLiteral($pane, '@test');

            // ── 5. Open completion menu with Tab ──────────────────
            $this->tmux->sendKey($pane, 'Tab');

            // Verify the completion menu appeared (at least one
            // suggestion with "testfiles/" in it).
            $menuCapture = $this->tmux->waitForCaptureContains(
                pane: $pane,
                needle: 'testfiles',
                timeout: 3.0,
            );

            self::assertStringContainsString(
                'testfiles',
                $menuCapture,
                'Completion menu must show file entries from the index.',
            );

            $this->saveAnsiSnapshot($pane, 'completion-menu-open');

            // ── 6. Accept the first suggestion ────────────────────
            $this->tmux->sendKey($pane, 'Tab');

            // Wait for editor to update.
            \usleep(200_000);

            $acceptedCapture = $this->tmux->capturePlain($pane);

            self::assertStringContainsString(
                'Hello',
                $acceptedCapture,
                'Editor must still contain "Hello" after accepting completion.',
            );

            self::assertStringContainsString(
                '@testfiles/',
                $acceptedCapture,
                'Editor must contain the completed @ path.',
            );

            // The editor should NOT show empty/cleared content.
            // This is the regression guard for issue #123.
            // Verify multiline content is preserved with Hello above
            // the completed @ path on its own line.
            self::assertStringContainsString(
                "Hello\n\n@testfiles/",
                $acceptedCapture,
                'Editor must preserve Hello on its own line above the completed @ path.',
            );

            $this->saveAnsiSnapshot($pane, 'completion-accepted');
        } finally {
            $this->cleanupTestDir($this->testProjectDir);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            \escapeshellarg($this->testProjectDir.'/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir.'/.hatfield', 0o777, true);
        @\mkdir($dir.'/home/.hatfield', 0o777, true);

        // Minimal isolated settings.  Only include what the TUI needs.
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
                                'cost' => ['input' => 1000.0, 'output' => 100000.0],
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

        $yaml = Yaml::dump($settings, 6, 4);
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

    private function cleanupTestDir(string $dir): void
    {
        // Remove test files we created so they don't pollute.
        @\unlink($dir.'/testfiles/alpha.txt');
        @\unlink($dir.'/testfiles/baker.txt');
        @\rmdir($dir.'/testfiles');
    }
}
