<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture;
use Ineersa\Tui\Tests\Support\SubagentParallelPendingRunningProgressFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed TmuxHarness proof: after resume, parent transcript and /agents-live
 * simultaneously show one child as pending and another as running for a parallel
 * subagent_progress snapshot (no live LLM, no catalog injection).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiSubagentParallelPendingRunningE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private string $snapshotDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->snapshotDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
        @mkdir($this->snapshotDir, 0o777, true);
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

    public function testResumeShowsParallelPendingAndRunningChildrenSimultaneously(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-parallel-pending-running',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);
            SubagentParallelPendingRunningProgressFixture::write($this->testProjectDir, $sessionId);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            usleep(250_000);

            // Transcript card headers: ○ worker [pending] and ● scout [running] simultaneously.
            $this->tmux->waitForCaptureContains(
                $pane,
                SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT,
                12.0,
                'Parent transcript must list scout artifact from parallel progress',
            );
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    $hasRunningScout = str_contains($cap, 'scout [running]') || str_contains($cap, '● scout');
                    $hasPendingWorker = str_contains($cap, 'worker [pending]') || str_contains($cap, '○ worker');
                    $hasBothArtifacts = str_contains($cap, SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT)
                        && str_contains($cap, SubagentParallelPendingRunningProgressFixture::ARTIFACT_WORKER);

                    return $hasRunningScout && $hasPendingWorker && $hasBothArtifacts;
                },
                timeout: 12.0,
                message: 'Transcript must show scout running and worker pending at the same time',
                history: 2500,
            );

            $transcriptCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT, $transcriptCap);
            $this->assertStringContainsString(SubagentParallelPendingRunningProgressFixture::ARTIFACT_WORKER, $transcriptCap);
            $this->assertTrue(
                str_contains($transcriptCap, 'scout [running]') || str_contains($transcriptCap, '● scout'),
                'Scout must be visible as running in transcript capture',
            );
            $this->assertTrue(
                str_contains($transcriptCap, 'worker [pending]') || str_contains($transcriptCap, '○ worker'),
                'Worker must be visible as pending in transcript capture',
            );
            $this->assertStringContainsString('parallel subagents', strtolower($transcriptCap));

            // Picker must also list both children with independent statuses.
            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/agents-live');
            $this->tmux->sendKey($pane, 'Enter');
            $this->tmux->waitForCaptureContains(
                $pane,
                SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT,
                10.0,
                'Picker must list scout artifact',
            );
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT)
                        && str_contains($cap, SubagentParallelPendingRunningProgressFixture::ARTIFACT_WORKER)
                        && str_contains($cap, 'running')
                        && str_contains($cap, 'pending');
                },
                timeout: 10.0,
                message: 'Picker must list both artifacts with pending and running labels',
                history: 2500,
            );

            $pickerCap = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(SubagentParallelPendingRunningProgressFixture::ARTIFACT_SCOUT, $pickerCap);
            $this->assertStringContainsString(SubagentParallelPendingRunningProgressFixture::ARTIFACT_WORKER, $pickerCap);
            $this->assertMatchesRegularExpression('/scout.*\[running\]|\[running\].*scout/s', $pickerCap);
            $this->assertMatchesRegularExpression('/worker.*\[pending\]|\[pending\].*worker/s', $pickerCap);

            $this->saveAnsiSnapshot($pane, 'subagent-parallel-pending-running');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'subagent-parallel-pending-running-FAILURE');
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
        if (!is_file($fixturePath)) {
            $this->fail("Fixture not found: {$fixturePath}");
        }

        $projectDir = ProjectDir::get();
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-subagent-parallel-pending-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-parallel-pending-running');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'deepseek' => ChildContextStatisticsFixture::deepseekProviderSettings(),
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
        ];
        $yaml = \Symfony\Component\Yaml\Yaml::dump(TuiE2eDatabaseEnv::withSingleLlmWorkerForReplay($settings), 6, 4);
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }

    private function saveAnsiSnapshot(TmuxPane $pane, string $name): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $ts = date('Ymd-His');
        file_put_contents(\sprintf('%s/%s-%s.ansi', $this->snapshotDir, $name, $ts), $ansi);
    }
}
