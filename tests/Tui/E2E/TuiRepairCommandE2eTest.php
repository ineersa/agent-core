<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Replay-backed tmux proof for /repair on a stale Cancelling session.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiRepairCommandE2eTest extends TestCase
{
    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $dbPath;
    private string $transportDbPath;
    private string $sessionId = '';

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

        $paths = TuiE2eDatabaseEnv::allocatePaths('tui-repair-');
        $this->dbPath = $paths['app'];
        $this->transportDbPath = $paths['transport'];
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

    public function testRepairCommandTerminalizesStaleCancellationInRealTui(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->freshAgentCommand(),
            prefix: 'tui-repair-create',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);
            $this->sessionId = $this->createSessionAndWaitForAssistant($pane);
            $this->tmux->sendKey($pane, 'C-d');
            usleep(300_000);
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'repair-create-FAILURE');
            throw $e;
        }

        $this->corruptSessionToStaleCancellation($this->sessionId);

        $resumePane = $this->tmux->startDetached(
            command: $this->resumeAgentCommand($this->sessionId),
            prefix: 'tui-repair-resume',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($resumePane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($resumePane);

            $this->tmux->sendKey($resumePane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($resumePane, '/repair');
            $this->tmux->sendKey($resumePane, 'Enter');

            $capture = $this->tmux->waitForCallback(
                $resumePane,
                static fn (string $cap): bool => str_contains($cap, 'Session repaired')
                    || str_contains($cap, 'stale cancellation terminalized'),
                timeout: 10.0,
                message: '/repair did not show repair success message in TUI',
                history: 2000,
            );

            $this->assertTrue(
                str_contains($capture, 'Session repaired')
                || str_contains($capture, 'stale cancellation terminalized'),
            );

            $this->assertAgentEndCancelledAppended($this->sessionId);
            $this->saveAnsiSnapshot($resumePane, 'repair-command-success');
            $this->tmux->sendKey($resumePane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($resumePane, 'repair-command-FAILURE');
            try {
                $this->tmux->sendKey($resumePane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function createSessionAndWaitForAssistant(TmuxPane $pane): string
    {
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
        $this->assertNotEmpty($sessionId);

        return $sessionId;
    }

    private function corruptSessionToStaleCancellation(string $sessionId): void
    {
        $sessionDir = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId;
        $lines = [
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":2,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"follow_up","payload":{"text":"run subagent"}},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":3,"turn_no":33,"type":"turn_advanced","payload":{"turn_no":33,"step_id":"follow_up-abc"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":4,"turn_no":33,"type":"llm_step_completed","payload":{"assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_00_abc","type":"function","function":{"name":"subagent","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:03+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":5,"turn_no":33,"type":"tool_execution_end","payload":{"tool_call_id":"call_00_abc","tool_name":"subagent","success":true},"ts":"2026-07-09T01:00:04+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":6,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:05+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$sessionId.'","seq":7,"turn_no":33,"type":"agent_command_rejected","payload":{"reason":"Command \"follow_up\" rejected because cancellation is in progress."},"ts":"2026-07-09T01:00:06+00:00"}',
        ];
        file_put_contents($sessionDir.'/events.jsonl', implode("\n", $lines)."\n");

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
        $state = new RunState(
            runId: $sessionId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: 7,
            pendingToolCalls: ['call_00_abc' => true],
            activeStepId: 'follow_up-abc',
        );
        file_put_contents($sessionDir.'/state.json', json_encode($serializer->normalize($state), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR));
    }

    private function freshAgentCommand(): string
    {
        return $this->agentCommandShell('agent ');
    }

    private function resumeAgentCommand(string $sessionId): string
    {
        return $this->agentCommandShell('agent --resume='.escapeshellarg($sessionId).' ');
    }

    private function agentCommandShell(string $agentTail): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-resume-minimal.json';
        if (!is_file($fixturePath)) {
            $this->fail("Fixture not found: {$fixturePath}");
        }

        return \sprintf(
            'APP_ENV=test %sHOME=%s HATFIELD_LLM_REPLAY_FIXTURE_PATH=%s %s %s %s--cwd=%s --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefix($this->dbPath, $this->transportDbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($fixturePath),
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($this->projectRoot.'/bin/console'),
            $agentTail,
            escapeshellarg($this->testProjectDir),
        );
    }

    private function assertAgentEndCancelledAppended(string $sessionId): void
    {
        $path = $this->testProjectDir.'/.hatfield/sessions/'.$sessionId.'/events.jsonl';
        $this->assertFileExists($path);

        $found = false;
        foreach (file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }
            if ('agent_end' === ($event['type'] ?? '') && 'cancelled' === ($event['payload']['reason'] ?? null)) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'events.jsonl must contain agent_end with reason=cancelled after /repair');
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-repair');
        TestDirectoryIsolation::createHatfieldTree($dir, withSessions: true);
        @mkdir($dir.'/home/.hatfield', 0o777, true);

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
                                    'off' => '0', 'minimal' => '0', 'low' => '0', 'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
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
