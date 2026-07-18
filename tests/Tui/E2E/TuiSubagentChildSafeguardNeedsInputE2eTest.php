<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\SubagentChildSafeguardNeedsInputFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed TmuxHarness proof for child SafeGuard needs-input attention latch.
 *
 * Thesis: selected child receives transient tool_question.requested (SafeGuard enum
 * approval) while parent subagent_progress remains/returns running; real TUI must
 * show needs-input / WaitingHuman and the approval overlay, then clear after answer.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiSubagentChildSafeguardNeedsInputE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $projectRoot;
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

    public function testSelectedChildToolQuestionNeedsInputSurvivesStaleRunningAndClearsOnAnswer(): void
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

            // Open live picker and enter the running child before the tool question
            // so child observation is active when ToolQuestionPoller emits.
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

            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, 'Child agent', 10.0, 'Live view working line must appear');

            // Seed pending tool_question after controller is live so startup cleanup
            // cannot cancel it, then append late parent running progress for the race.
            SubagentChildSafeguardNeedsInputFixture::seedPendingToolQuestion(
                $this->appDbAbsolutePath,
                $childRunId,
            );
            SubagentChildSafeguardNeedsInputFixture::appendStaleRunningProgress(
                $this->testProjectDir,
                $sessionId,
            );

            $approvalCapture = $this->tmux->waitForCaptureContains(
                $pane,
                '✅ Allow once',
                20.0,
                'Child SafeGuard enum approval overlay must appear',
            );
            $this->assertStringContainsString('📌 Always allow', $approvalCapture);
            $this->assertStringContainsString('❌ Block', $approvalCapture);
            $this->assertStringContainsString(
                SubagentChildSafeguardNeedsInputFixture::PROMPT,
                $approvalCapture,
                'Overlay must show SafeGuard-like prompt',
            );

            // Needs-input evidence despite parent progress remaining nonterminal running.
            $this->tmux->waitForCaptureContains(
                $pane,
                'Child waiting for your input',
                12.0,
                'Live view working line must show child waiting while tool question is pending',
            );

            // Answer Allow once (default selection).
            $this->tmux->sendKey($pane, 'Enter');

            // Full-render predicate: do not accept partial frames where footer already
            // shows running while the working line still says "Child waiting...".
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    $overlayGone = !str_contains($cap, '✅ Allow once')
                        && !str_contains($cap, '📌 Always allow')
                        && !str_contains($cap, '❌ Block');
                    $waitingGone = !str_contains($cap, 'Child waiting for your input');
                    $nonWaitingEvidence = str_contains($cap, 'Child agent working')
                        || str_contains($cap, 'Child agent idle')
                        || (str_contains($cap, 'agents-live') && str_contains($cap, '[running]'))
                        || (str_contains($cap, 'scout') && str_contains($cap, '[running]'));

                    return $overlayGone && $waitingGone && $nonWaitingEvidence;
                },
                timeout: 15.0,
                message: 'Post-answer frame must drop overlay + waiting line and show non-waiting selected child',
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
            $this->assertStringNotContainsString(
                '📌 Always allow',
                $after,
                'SafeGuard options must not remain after answer',
            );
            $this->assertTrue(
                str_contains($after, 'Child agent working')
                || str_contains($after, 'Child agent idle')
                || (str_contains($after, 'scout') && str_contains($after, '[running]')),
                'Selected child must show non-waiting evidence after answer',
            );
        } catch (\Throwable $e) {
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
}
