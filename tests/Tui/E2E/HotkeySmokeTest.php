<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test for Ctrl+J multiline newline and /hotkeys command.
 *
 * Tests:
 *  A. Ctrl+J inserts a newline in the editor (purely visual proof).
 *  B. /hotkeys renders a themed keyboard shortcuts table.
 *
 * Design principles:
 *  - No events.jsonl, no fixed sleeps, no session artifacts.
 *  - No shell-prefix commands, no LLM response waiting.
 *  - All assertions use tmux pane capture/history polling only.
 *  - Unique random markers per test avoid matching static help/text.
 *
 * @group tui-e2e
 */
#[Group('tui-e2e')]
final class HotkeySmokeTest extends TestCase
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
        $this->snapshotDir = $this->testProjectDir . '/.hatfield/tmp/tui/smoke';
        @\mkdir($this->snapshotDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        // TmuxHarness destructor kills all sessions.
    }

    /**
     * @test
     *
     * Ctrl+J (C-j in tmux) inserts a newline in the editor.
     *
     * Proof strategy (purely visual — no submission, no LLM, no shell):
     *  1. Type a unique first-line marker.
     *  2. Send Ctrl+J (C-j).
     *  3. Type a unique second-line marker.
     *  4. Capture the pane while still in the editor (no Enter/submit).
     *  5. Assert the second marker appears on a line BELOW the first marker.
     *
     * If C-j does nothing, both markers concatenate on the same line
     * and the line-order assertion fails.
     *
     * If C-j erroneously submits (Enter-like), the first marker disappears
     * from the editor and the assertion that it's in the capture fails.
     */
    public function testCtrlJInsertsNewlineInEditor(): void
    {
        $line1Marker = 'cj-1-' . bin2hex(random_bytes(4));
        $line2Marker = 'cj-2-' . bin2hex(random_bytes(4));

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-hk-cj',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot (logo █ visible).
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Type first line marker.
        $this->tmux->sendLiteral($pane, $line1Marker);

        // Insert newline with Ctrl+J (tmux name: C-j).
        $this->tmux->sendKey($pane, 'C-j');

        // Type second line marker.
        $this->tmux->sendLiteral($pane, $line2Marker);

        // Poll until both markers are visible in the pane.
        $capture = $this->tmux->waitForCallback(
            $pane,
            static function (string $capture) use ($line1Marker, $line2Marker): bool {
                return str_contains($capture, $line1Marker)
                    && str_contains($capture, $line2Marker);
            },
            timeout: 5.0,
            message: 'Editor did not show both typed lines',
            history: 500,
        );

        // Find the line indices of each marker.
        $lines = explode("\n", $capture);
        $line1Idx = null;
        $line2Idx = null;

        foreach ($lines as $idx => $line) {
            if (str_contains($line, $line1Marker)) {
                $line1Idx = $idx;
            }
            if (str_contains($line, $line2Marker)) {
                $line2Idx = $idx;
            }
        }

        self::assertNotNull(
            $line1Idx,
            \sprintf('First line marker "%s" should be visible in editor', $line1Marker),
        );
        self::assertNotNull(
            $line2Idx,
            \sprintf('Second line marker "%s" should be visible in editor', $line2Marker),
        );
        self::assertGreaterThan(
            $line1Idx,
            $line2Idx,
            \sprintf(
                'Second line "%s" should be on a line below first line "%s" (%d vs %d), '
                . 'proving C-j inserted a newline between them',
                $line2Marker,
                $line1Marker,
                $line2Idx,
                $line1Idx,
            ),
        );

        $this->saveAnsiSnapshot($pane, 'ctrl-j-newline');
    }

    /**
     * @test
     *
     * /hotkeys renders a themed keyboard shortcuts table.
     *
     * Types /hotkeys, presses Enter, and asserts that the themed table
     * heading and representative entries are visible in the tmux pane.
     *
     * No LLM involvement — /hotkeys is a local slash command.
     * The box-drawing table and themed text are rendered to the transcript.
     * On plain-text capture, ANSI is stripped but structural content
     * (headings, box chars, key names, descriptions) is visible.
     */
    public function testHotkeysCommandRendersKeyboardShortcutTable(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'hatfield-hk-table',
            width: 120,
            height: 40,
            cwd: $this->testProjectDir,
        );

        // Wait for agent boot.
        $this->tmux->waitForCaptureContains($pane, '█', 10.0);

        // Type /hotkeys and submit.
        $this->tmux->sendLiteral($pane, '/hotkeys');
        $this->tmux->sendKey($pane, 'Enter');

        // Wait for the themed hotkeys heading to appear.
        $this->tmux->waitForCallback(
            $pane,
            static function (string $capture): bool {
                return str_contains($capture, 'Keyboard shortcuts');
            },
            timeout: 5.0,
            message: '/hotkeys table never appeared',
            history: 2000,
        );

        // Capture the full history to check table content.
        $capture = $this->tmux->capturePlainWithHistory($pane, 2000);

        // Structural box-drawing characters must be present.
        $boxChars = ['┌', '├', '└', '│', '┐', '┤', '┘'];
        foreach ($boxChars as $ch) {
            self::assertStringContainsString(
                $ch,
                $capture,
                \sprintf('/hotkeys output should contain box-drawing char "%s"', $ch),
            );
        }

        // Representative entries from each context.
        $requiredEntries = [
            // Global
            'Ctrl+C',
            'Ctrl+D',
            // Editor
            'Ctrl+J',
            'Insert newline',
            'Submit prompt',
            'Enter',
            // Completion
            'Tab',
            // Local command heading
            'Keyboard shortcuts',
        ];

        foreach ($requiredEntries as $entry) {
            self::assertStringContainsString(
                $entry,
                $capture,
                \sprintf('/hotkeys output should contain "%s"', $entry),
            );
        }

        $this->saveAnsiSnapshot($pane, 'hotkeys-table');
    }

    // ── Helpers ───────────────────────────────────────────────

    private function agentCommand(): string
    {
        [$php, $script] = AgentTestExecutable::command();

        return \sprintf(
            'APP_ENV=dev HOME=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            \escapeshellarg($this->testProjectDir . '/home'),
            \escapeshellarg($php),
            \escapeshellarg($script),
        );
    }

    /**
     * Create an isolated project directory with minimal settings.
     *
     * No SafeGuard shell-config is needed — neither test uses shell commands.
     */
    private function createIsolatedProjectDir(): string
    {
        $dir = \sprintf(
            '%s/var/tmp/tui-e2e-hk-%s',
            $this->projectRoot,
            \bin2hex(\random_bytes(6)),
        );
        @\mkdir($dir . '/.hatfield', 0o777, true);
        @\mkdir($dir . '/home/.hatfield', 0o777, true);

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
        ];

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        \file_put_contents($dir . '/.hatfield/settings.yaml', $yaml);
        \file_put_contents($dir . '/home/.hatfield/settings.yaml', $yaml);

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
