<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Minimal tmux proof for status-row stability and transient reasoning notice.
 *
 * Test thesis: real terminal Shift+Tab shows the panel-only reasoning line above
 * the working area; submitting the next turn removes that line while footer ◆
 * remains; when the notice clears, the footer-to-editor-region line gap stays constant across notice, submit, and idle (working slot stays one row via reserved blank).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiStatusRowReasoningNoticeE2eTest extends TestCase
{
    private const string SHIFT_TAB = "\x1b[Z";

    private const string REPLAY_PROMPT = 'Respond with exactly one sentence: the sky is blue.';

    private const string FOOTER_ANCHOR = '⎇';

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

    public function testShiftTabReasoningNoticeClearsOnSubmitWithoutShiftingFooterAnchor(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-status-reasoning',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $baseline = $this->tmux->capturePlainWithHistory($pane, 2000);
            $baselineLayoutGap = $this->footerToEditorRegionGap($baseline);

            $this->tmux->sendLiteral($pane, self::SHIFT_TAB);

            $withNotice = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, 'reasoning') && str_contains($cap, 'minimal'),
                timeout: 5.0,
                message: 'Shift+Tab reasoning status panel line did not appear',
                history: 2000,
            );

            $this->assertStringContainsString('◆', $withNotice, 'Footer diamond must remain after Shift+Tab');
            $this->assertMatchesRegularExpression('/\s{2}reasoning\s+minimal/', $withNotice, 'Status panel reasoning row expected');
            $this->saveAnsiSnapshot($pane, 'status-reasoning-after-shift-tab');

            $this->assertSame($baselineLayoutGap, $this->footerToEditorRegionGap($withNotice), 'Footer/region gap must stay stable when notice appears');

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $afterSubmit = $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => !preg_match('/\s{2}reasoning\s+\S+/', $cap)
                    && (str_contains($cap, 'Working...') || str_contains($cap, '◐')),
                timeout: 10.0,
                message: 'Transient reasoning panel line still visible after submit',
                history: 2000,
            );

            $this->assertStringContainsString('◆', $afterSubmit);
            $this->saveAnsiSnapshot($pane, 'status-reasoning-after-submit');

            $this->assertSame($baselineLayoutGap, $this->footerToEditorRegionGap($afterSubmit), 'Footer/region gap must match baseline once notice clears');

            $this->tmux->waitForCallback(
                $pane,
                fn (string $cap): bool => $this->tailShowsIdleWithoutActiveWorking($cap),
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Idle status not restored after replay turn',
                history: 2000,
            );

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '◇') || str_contains($cap, '✕'),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Replay assistant block did not appear',
                history: 2000,
            );

            $idleCapture = $this->tmux->capturePlainWithHistory($pane, 2000);
            $this->assertDoesNotMatchRegularExpression('/\s{2}reasoning\s+\S+/', $idleCapture, 'Transient reasoning panel line must stay cleared after turn');
            $this->assertTrue($this->tailShowsIdleWithoutActiveWorking($idleCapture), 'Live status row must show idle after replay turn');

            $this->saveAnsiSnapshot($pane, 'status-reasoning-idle-baseline');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'status-reasoning-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-simple-text-response.json';
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-status-reasoning-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-status-reasoning');
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

    private function footerToEditorRegionGap(string $capture, int $tailLines = 80): int
    {
        $lines = explode("\n", $capture);
        $tail = implode("\n", \array_slice($lines, -$tailLines));

        return $this->lineIndexLast($tail, self::FOOTER_ANCHOR) - $this->editorRegionSeparatorIndex($tail);
    }

    private function editorRegionSeparatorIndex(string $capture): int
    {
        $lines = explode("\n", $capture);
        $footerIndex = $this->lineIndexLast($capture, self::FOOTER_ANCHOR);

        for ($i = $footerIndex - 1; $i >= 0; --$i) {
            if (str_contains($lines[$i], '─')) {
                return $i;
            }
        }

        $this->fail('Editor-region separator missing above footer in tmux capture');
    }

    private function lineIndexLast(string $capture, string $needle): int
    {
        $last = null;
        foreach (explode("\n", $capture) as $i => $line) {
            if (str_contains($line, $needle)) {
                $last = $i;
            }
        }

        if (null === $last) {
            $this->fail('Anchor missing from tmux capture: '.$needle);
        }

        return $last;
    }

    private function tailShowsIdleWithoutActiveWorking(string $capture, int $lineCount = 45): bool
    {
        unset($lineCount);
        $lines = explode("\n", $capture);

        for ($i = \count($lines) - 1; $i >= 0; --$i) {
            $line = $lines[$i];
            if (preg_match('/^\s+●\s*idle/', $line)) {
                return true;
            }
            if (preg_match('/^\s+◐/', $line)) {
                return false;
            }
        }

        return false;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, $ts), $ansi);
    }
}
