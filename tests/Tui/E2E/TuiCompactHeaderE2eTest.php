<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Real-terminal smoke: compact-header renders pinned above the editor after first tick.
 */
#[Group('tui-e2e-replay')]
final class TuiCompactHeaderE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;

    protected function setUp(): void
    {
        if (!TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is not installed. Skipping TUI e2e tests.');
        }

        $this->tmux = new TmuxHarness();
        $this->projectRoot = ProjectDir::get();
        $this->testProjectDir = $this->createIsolatedProjectDir();
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

    public function testCompactHeaderRendersPlantedSkillAboveEditor(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(withPrompt: false),
            prefix: 'hatfield-compact-header',
            cwd: $this->testProjectDir,
        );

        $this->tmux->waitForCaptureContains($pane, '█', timeout: 15.0);
        $this->tmux->waitForCaptureContains($pane, 'e2e-compact-header', timeout: 15.0);

        $capture = $this->tmux->capturePlain($pane);
        $this->assertStringContainsString('skills', $capture);
        $this->assertStringContainsString('e2e-compact-header', $capture);
    }

    private function agentCommand(bool $withPrompt = false): string
    {
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';
        $startupFixture = __DIR__.'/fixtures/tui-startup-prompt-response.json';
        $fixtureEnv = is_file($startupFixture)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($startupFixture).' '
            : '';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-compact-');

        $dbPath = $paths['app'];

        $transportDbPath = $paths['transport'];
        $promptArg = $withPrompt ? ' --prompt="hello"' : '';

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s %s agent --model=llama_cpp_test/test%s --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($dbPath, $transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg($php),
            escapeshellarg($script),
            $promptArg,
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-compact', 0o777);
        @mkdir($dir.'/.hatfield', 0o777, true);
        @mkdir($dir.'/.agents/skills/e2e-compact-header', 0o777, true);
        file_put_contents(
            $dir.'/.agents/skills/e2e-compact-header/SKILL.md',
            "---\nname: e2e-compact-header\ndescription: compact header tmux proof\n---\n",
        );
        @mkdir($dir.'/home/.hatfield', 0o777, true);

        $settings = [
            'ai' => [
                'default_model' => 'llama_cpp_test/test',
                'default_reasoning' => 'off',
                'providers' => [
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://127.0.0.1:9052/v1',
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
        file_put_contents($dir.'/.hatfield/settings.yaml', $yaml);
        file_put_contents($dir.'/home/.hatfield/settings.yaml', $yaml);

        return $dir;
    }
}
