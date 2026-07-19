<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\SubagentChildSafeguardNeedsInputFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed TmuxHarness proof for child SafeGuard needs-input visibility.
 *
 * Exact live ordering that previously failed on a6e51ceaa:
 * main active → child tool_question.requested → needs-input without entering child
 * → enter child → overlay → Ctrl+\ return main → overlay gone, needs-input remains
 * → re-enter child → same overlay → answer → needs-input clears.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiSubagentChildSafeguardNeedsInputE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $projectRoot;
    private string $snapshotDir;
    private string $appDbPath;
    private string $transportDbPath;
    private string $appDbEnvPath;
    private string $transportDbEnvPath;
    private string $appDbAbsolutePath;

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

        $paths = TuiE2eDatabaseEnv::allocateIsolatedPaths(
            $this->projectRoot,
            $this->testProjectDir,
            'tui-sg-child-needs',
        );
        $this->appDbPath = $paths['app'];
        $this->transportDbPath = $paths['transport'];
        $this->appDbEnvPath = $paths['appEnv'];
        $this->transportDbEnvPath = $paths['transportEnv'];
        $this->appDbAbsolutePath = $paths['appAbsolute'];

        $this->migrateTestDatabase();
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

    public function testMainSeededChildToolQuestionShowsNeedsInputOverlayOnlyInChildView(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-sg-child-needs',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentChildSafeguardNeedsInputFixture::write($this->testProjectDir, $sessionId);
            $childRunId = SubagentChildSafeguardNeedsInputFixture::childRunId($sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            usleep(300_000);

            // Live order: remain on main, then seed SafeGuard tool_question for child.
            // Wait briefly so resume attach/catalog settle and active-tick drain is running
            // while catalog still lists the nonterminal child.
            usleep(500_000);
            SubagentChildSafeguardNeedsInputFixture::seedPendingToolQuestion(
                $this->appDbAbsolutePath,
                $childRunId,
            );
            // Give ToolQuestionPoller (0.5s) + parent events() drain time before opening picker.
            usleep(1_200_000);

            // Open picker and prove needs-input before entering child.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains(
                $pane,
                SubagentChildSafeguardNeedsInputFixture::ARTIFACT_ID,
                10.0,
                'Picker must list SafeGuard fixture child',
            );
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, '⚠ needs input')
                        && str_contains($cap, SubagentChildSafeguardNeedsInputFixture::ARTIFACT_ID)
                        && !str_contains($cap, '✅ Allow once');
                },
                timeout: 20.0,
                message: 'Main/picker must show needs-input for child without opening the overlay',
                history: 2500,
            );

            // Enter child and prove overlay appears only in child view.
            $this->tmux->sendKey($pane, 'Enter');
            $approvalCapture = $this->tmux->waitForCaptureContains(
                $pane,
                '✅ Allow once',
                20.0,
                'Child SafeGuard enum approval overlay must appear in child view',
            );
            $this->assertStringContainsString('📌 Always allow', $approvalCapture);
            $this->assertStringContainsString('❌ Block', $approvalCapture);
            $this->assertStringContainsString(
                SubagentChildSafeguardNeedsInputFixture::PROMPT,
                $approvalCapture,
                'Overlay must show SafeGuard-like prompt',
            );
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, '✅ Allow once')
                        && str_contains($cap, 'Child waiting for your input')
                        && str_contains($cap, '[waiting_human]');
                },
                timeout: 12.0,
                message: 'Child view must show overlay + waiting line + [waiting_human]',
                history: 2500,
            );

            // Ctrl+\ return to main: overlay must disappear immediately (coordinator keeps request).
            $this->tmux->sendKey($pane, 'C-\\');
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    $overlayGone = !str_contains($cap, '✅ Allow once')
                        && !str_contains($cap, '📌 Always allow')
                        && !str_contains($cap, '❌ Block')
                        && !str_contains($cap, SubagentChildSafeguardNeedsInputFixture::PROMPT);
                    // Main transcript evidence (not live-view chrome).
                    $onMain = str_contains($cap, '● idle')
                        || str_contains($cap, 'Resumed run')
                        || str_contains($cap, 'Returned to main session');

                    return $overlayGone && $onMain;
                },
                timeout: 12.0,
                message: 'Return to main must close child overlay without answering/cancelling',
                history: 2500,
            );

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, '⚠ needs input')
                        && str_contains($cap, SubagentChildSafeguardNeedsInputFixture::ARTIFACT_ID)
                        && !str_contains($cap, '✅ Allow once');
                },
                timeout: 12.0,
                message: 'After return-to-main, picker must still show needs-input without overlay',
                history: 2500,
            );

            // Re-enter same child: same overlay must reopen, then answer clears needs-input.
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains(
                $pane,
                '✅ Allow once',
                20.0,
                'Re-entering child must reopen the same SafeGuard overlay',
            );
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    $overlayGone = !str_contains($cap, '✅ Allow once')
                        && !str_contains($cap, '📌 Always allow')
                        && !str_contains($cap, '❌ Block');
                    $waitingGone = !str_contains($cap, 'Child waiting for your input')
                        && !str_contains($cap, '[waiting_human]');
                    $nonWaitingEvidence = str_contains($cap, 'Child agent working')
                        || str_contains($cap, 'Child agent idle')
                        || (str_contains($cap, 'scout') && str_contains($cap, '[running]'))
                        || (str_contains($cap, 'agents-live') && str_contains($cap, '[running]'));

                    return $overlayGone && $waitingGone && $nonWaitingEvidence;
                },
                timeout: 15.0,
                message: 'Post-answer frame must drop overlay + waiting evidence',
                history: 2500,
            );

            $after = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringNotContainsString(
                'Child waiting for your input',
                $after,
                'Needs-input working line must clear after answer',
            );
            $this->assertStringNotContainsString(
                '✅ Allow once',
                $after,
                'SafeGuard overlay must not remain after answer',
            );
            $this->saveAnsiSnapshot($pane, 'sg-child-needs-input-success');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'sg-child-needs-input-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
        usleep(150_000);
        $this->tmux->sendLiteral($pane, 'hi');
        $this->tmux->sendKey($pane, 'Enter');
        $sessionId = null;
        $this->tmux->waitForCallback(
            $pane,
            static function (string $cap) use (&$sessionId): bool {
                if (!str_contains($cap, '◇') && !str_contains($cap, '✕')) {
                    return false;
                }
                if (!preg_match('/session\s+(\d+)/', $cap, $matches)) {
                    return false;
                }
                $sessionId = $matches[1];

                return true;
            },
            timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
            message: 'Assistant block and session id must both appear in capture',
            history: 2000,
        );
        $this->assertNotEmpty($sessionId, 'Session id must appear in the same capture as assistant/error glyph');

        return $sessionId;
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixForIsolatedEnv($this->appDbEnvPath, $this->transportDbEnvPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg($php),
            escapeshellarg($script),
        );
    }

    private function migrateTestDatabase(): void
    {
        $cmd = \sprintf(
            'cd %s && APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HATFIELD_TEST_MESSENGER_TRANSPORT_DATABASE_PATH=%s %s %s doctrine:migrations:migrate --no-interaction 2>&1',
            escapeshellarg($this->testProjectDir),
            escapeshellarg($this->appDbEnvPath),
            escapeshellarg($this->transportDbEnvPath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
        );

        exec($cmd, $output, $exitCode);
        if (0 !== $exitCode) {
            $this->fail('Failed to migrate test database for child SafeGuard needs-input E2E: '.implode("\n", $output));
        }

        TuiE2eDatabaseEnv::ensureIsolatedMessengerTransportSchema(
            TuiE2eDatabaseEnv::isolatedSqliteAbsolutePath($this->testProjectDir, $this->transportDbPath),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-sg-child-needs');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);
        $settings = [
            'ai' => [
                'providers' => [
                    'llama_cpp_test' => [
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
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'thinking_level_map' => ['off' => '0'],
                                'cost' => ['input' => 0, 'output' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
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
