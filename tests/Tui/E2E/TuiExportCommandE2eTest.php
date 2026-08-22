<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Replay-backed TmuxHarness proof that the real TUI `/export` path surfaces
 * provider-visible tools from the canonical llm_step_completed snapshot.
 *
 * Test thesis: a real replay-backed model turn resolves provider tools, persists
 * the compact available-tools snapshot through worker/canonical events, and the
 * actual `/export` slash route writes HTML with a visible Available tools section.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiExportCommandE2eTest extends TestCase
{
    private const string REPLAY_PROMPT = 'Respond with exactly one sentence: the sky is blue.';

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
        $this->tmux->setSnapshotDir($this->testProjectDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmux)) {
            $this->tmux->killAll();
        }

        if (isset($this->testProjectDir) && '' !== $this->testProjectDir) {
            TestDirectoryIsolation::removeDirectory($this->testProjectDir);
        }
    }

    public function testExportSlashCommandWritesAvailableToolsFromCanonicalSnapshot(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-export-tools',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $sessionId = $this->createSessionAndWaitForAssistant($pane);

            // Canonical llm_step_completed is durable only after the turn settles.
            $this->tmux->waitForCallback(
                $pane,
                function (string $cap) use ($sessionId): bool {
                    if (!$this->captureShowsIdleWithoutActiveWorking($cap)) {
                        return false;
                    }

                    $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
                    if (!is_file($eventsPath)) {
                        return false;
                    }

                    return null !== $this->findLatestEvent($eventsPath, 'llm_step_completed');
                },
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Replay turn must finish and persist canonical llm_step_completed before /export',
                history: 2000,
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/export');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains(
                $pane,
                'Session exported to:',
                12.0,
                'Real /export slash route must report the HTML path',
            );
            $this->tmux->saveAnsiSnapshot($pane, 'export-available-tools-after-slash');

            $htmlPath = $this->testProjectDir.'/hatfield-session-'.$sessionId.'.html';
            $this->assertFileExists($htmlPath, 'Actual TUI /export must write hatfield-session-<id>.html');

            $html = (string) file_get_contents($htmlPath);
            $this->assertStringContainsString('Available tools', $html);
            $this->assertStringContainsString('class="available-tools"', $html);
            $this->assertStringContainsString('schema tokens', $html);
            $this->assertStringContainsString('read', $html);

            $eventsPath = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
            $this->assertFileExists($eventsPath);
            $completed = $this->findLatestEvent($eventsPath, 'llm_step_completed');
            $this->assertNotNull($completed, 'Canonical llm_step_completed event must exist');
            $payload = \is_array($completed['payload'] ?? null) ? $completed['payload'] : [];
            $this->assertArrayHasKey('available_tools', $payload);
            $this->assertIsArray($payload['available_tools']);
            $this->assertNotEmpty($payload['available_tools']);
            $this->assertContains(
                'read',
                $payload['available_tools'],
                'Compact list<string> snapshot must include stable built-in tool name read',
            );
            $this->assertArrayHasKey('available_tools_schema_tokens_estimate', $payload);
            $this->assertIsInt($payload['available_tools_schema_tokens_estimate']);
            $this->assertGreaterThan(0, $payload['available_tools_schema_tokens_estimate']);

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'export-available-tools-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
                // Best-effort detach during failure diagnostics.
            }
            throw $e;
        }
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
        $this->tmux->waitForTuiReady($pane);
        $this->tmux->sendLiteral($pane, self::REPLAY_PROMPT);
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

        return (string) $sessionId;
    }

    private function captureShowsIdleWithoutActiveWorking(string $capture): bool
    {
        if (str_contains($capture, 'Working...') || str_contains($capture, 'Running...')) {
            return false;
        }

        // Live status row uses ◐ while busy and ◆ when idle.
        return str_contains($capture, '◆') && !str_contains($capture, '◐ Working');
    }

    private function agentCommand(): string
    {
        $fixturePath = $this->projectRoot.'/tests/Tui/E2E/fixtures/tui-simple-text-response.json';
        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-export-tools-');

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s agent --model=llama_cpp_test/test 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($paths['app'], $paths['transport']),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-export-tools');
        TestDirectoryIsolation::createHatfieldTree($dir);
        TestDirectoryIsolation::createHatfieldTree($dir.'/home');

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
        ];
        TuiE2eDatabaseEnv::writeReplaySettings($dir, $settings);

        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestEvent(string $eventsPath, string $type): ?array
    {
        $lines = file($eventsPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return null;
        }

        $latest = null;
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($decoded)) {
                continue;
            }
            if (($decoded['type'] ?? null) === $type) {
                $latest = $decoded;
            }
        }

        return $latest;
    }
}
