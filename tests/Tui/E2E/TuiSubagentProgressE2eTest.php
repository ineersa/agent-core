<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Tests\E2E\Support\TuiE2eSessionCatalogSeeder;
use Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture;
use Ineersa\Tui\Tests\Support\SubagentProgressEventsFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TmuxHarness proof: structured subagent progress renders inline after resume replay.
 *
 * Seeds catalog + canonical events without an LLM turn; card/handoff formatting is
 * the unique tmux contract under test.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiSubagentProgressE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $testProjectDir;
    private ?string $comparisonDir = null;

    /** @var array{app: string, transport: string, appEnv: string, transportEnv: string}|null */
    private ?array $dbPaths = null;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->testProjectDir = $this->createIsolatedProjectDir();
        $this->tmux->setSnapshotDir($this->testProjectDir);
        $this->comparisonDir = ProjectDir::get().'/var/tmp/tui-visual-readability/after';
        @mkdir($this->comparisonDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }
        if (isset($this->testProjectDir) && is_dir($this->testProjectDir)) {
            $smokeDir = $this->testProjectDir.'/.hatfield/tmp/tui/smoke';
            if (null !== $this->comparisonDir && is_dir($smokeDir)) {
                foreach (glob($smokeDir.'/*.ansi') ?: [] as $ansi) {
                    @copy($ansi, $this->comparisonDir.'/'.basename($ansi));
                }
            }
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testResumeShowsStructuredSubagentProgressWithoutSpam(): void
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before seeding');
        $sessionId = TuiE2eSessionCatalogSeeder::createSession(
            $this->testProjectDir,
            $paths['appEnv'],
            $paths['transportEnv'],
            'Run a scout subagent.',
        );
        SubagentProgressEventsFixture::write($this->testProjectDir, $sessionId);

        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-subagent-progress',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForTuiReady($pane);

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, "/resume {$sessionId}");
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForTuiReady($pane);
            // Progress proof is fixture transcript content after resume, not keystroke settle.
            $this->tmux->waitForCaptureContains($pane, 'agent_e2e_progress_fixture', 10.0, 'Resumed transcript must show fixture artifact');

            $capture = $this->tmux->capturePlainWithHistory($pane, 2500);

            // Polished card format after SubagentProgressCardWidget:
            //   ✓ scout [completed] — glyph + agent_name + status badge
            //   Task Inspect TUI subagent rendering — no colon
            //   Artifact artifacts/agents/agent_e2e_progress_fixture — full path, singular, no colon
            $this->assertStringContainsString('✓ scout [completed]', $capture);
            $this->assertStringContainsString('Task Inspect TUI subagent rendering', $capture);
            $this->assertStringContainsString('Artifact artifacts/agents/agent_e2e_progress_fixture', $capture);
            $this->assertStringContainsString('agent_e2e_progress_fixture', $capture);
            $this->assertStringContainsString('3 LLM steps', $capture);
            $this->assertStringNotContainsString(' turns', $capture);
            $this->assertStringContainsString('deepseek/deepseek-v4-flash', $capture);
            $this->assertStringContainsString(ChildContextStatisticsFixture::TRANSCRIPT_CTX_LINE, $capture, 'Resumed parent transcript card must show child context usage');
            $this->assertStringContainsString('Use agent_retrieve', $capture);
            $this->assertStringNotContainsString('running scout |', $capture);
            $this->assertStringNotContainsString('parallel subagents running', $capture);

            $this->assertMatchesRegularExpression(
                '/╭─.*\n.*╰─\n\s*\n\s*Handoff/s',
                $capture,
                'Progress card and handoff markdown should be separated by a blank row',
            );

            $this->assertStringContainsString('finding one about transcript rendering', $capture);
            $this->assertStringContainsString('more line', $capture, 'Collapsed handoff must show preview ellipsis');
            $this->assertStringNotContainsString('scout-handoff-tail-line', $capture, 'Collapsed handoff must hide the long tail');

            $ansiHistory = $this->tmux->captureAnsiWithHistory($pane, 3000);
            $this->assertMatchesRegularExpression(
                '/\x1b\[3m(?:\x1b\[[0-9;]*m)*… \d+ more lines?/',
                $ansiHistory,
                'Collapsed handoff ellipsis must keep italic ANSI in real tmux render',
            );

            $turnOneCount = substr_count($capture, 'turn 1');
            $this->assertLessThanOrEqual(1, $turnOneCount, 'Coalesced progress must not repeat stale turn 1 spam');

            $this->tmux->saveAnsiSnapshot($pane, 'subagent-progress-resume');
            $this->persistComparisonArtifacts($pane, 'subagent-progress-resume');
            $this->tmux->sendKey($pane, 'C-d');
            // Wait for natural exit before tearDown removeDirectory; killAll alone can leave a
            // still-writing HOME catalog under the isolated tree.
            $this->tmux->waitUntilPaneExits($pane, 10.0);
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'subagent-progress-resume-FAILURE');
            $this->persistComparisonArtifacts($pane, 'subagent-progress-resume-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
                $this->tmux->waitUntilPaneExits($pane, 10.0);
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function persistComparisonArtifacts(TmuxPane $pane, string $tag): void
    {
        if (null === $this->comparisonDir) {
            return;
        }

        @mkdir($this->comparisonDir, 0o777, true);
        $stamp = date('Ymd-His');
        file_put_contents(
            \sprintf('%s/%s-history-%s.ansi', $this->comparisonDir, $tag, $stamp),
            $this->tmux->captureAnsiWithHistory($pane, 3000),
        );
        file_put_contents(
            \sprintf('%s/%s-history-%s.txt', $this->comparisonDir, $tag, $stamp),
            $this->tmux->capturePlainWithHistory($pane, 3000),
        );
    }

    private function agentCommand(): string
    {
        $paths = $this->dbPaths ?? $this->fail('DB paths must be allocated before building agent command');

        // Draft boot only — no LLM fixture/env; resume loads seeded canonical events.
        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s agent --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixWithLowLatencyMessenger(
                $paths['appEnv'],
                $paths['transportEnv'],
                $this->testProjectDir,
            ),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(ProjectDir::get().'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-subagent-progress');
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);

        $allocated = TuiE2eDatabaseEnv::allocateIsolatedPaths(
            ProjectDir::get(),
            $dir,
            'tui-subagent-progress-',
        );
        $this->dbPaths = [
            'app' => $allocated['app'],
            'transport' => $allocated['transport'],
            'appEnv' => $allocated['appEnv'],
            'transportEnv' => $allocated['transportEnv'],
        ];

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
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }
}
