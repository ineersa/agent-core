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
 * Real tmux proof for /repair on a stale Cancelling session (no LLM invocation).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiRepairCommandE2eTest extends TestCase
{
    private const string SESSION_ID = '42';

    private TmuxHarness $tmux;
    private string $projectRoot;
    private string $testProjectDir;
    private string $snapshotDir;
    private string $dbPath;
    private string $transportDbPath;
    private string $appDbAbsolutePath;
    private string $appDbEnvPath;
    private string $transportDbEnvPath;

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
            'tui-repair-',
        );
        $this->dbPath = $paths['app'];
        $this->transportDbPath = $paths['transport'];
        $this->appDbAbsolutePath = $paths['appAbsolute'];
        $this->appDbEnvPath = $paths['appEnv'];
        $this->transportDbEnvPath = $paths['transportEnv'];

        $this->migrateTestDatabase();
        $this->seedStaleCancellationSession();
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
            command: $this->agentCommand(),
            prefix: 'tui-repair-smoke',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(50_000);
            $this->tmux->sendLiteral($pane, '/repair');
            $this->tmux->sendKey($pane, 'Enter');

            $capture = $this->tmux->waitForCallback(
                $pane,
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

            $this->assertAgentEndCancelledAppended();
            $this->saveAnsiSnapshot($pane, 'repair-command-success');
            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'repair-command-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function agentCommand(): string
    {
        $php = \PHP_BINARY;
        $script = $this->projectRoot.'/bin/console';

        return \sprintf(
            'APP_ENV=test %sHOME=%s %s %s agent --resume=%s --cwd=%s --model=llama_cpp_test/test --tools-excluded=bash 2>&1',
            TuiE2eDatabaseEnv::shellPrefixForIsolatedEnv($this->appDbEnvPath, $this->transportDbEnvPath),
            escapeshellarg($this->testProjectDir.'/home'),
            escapeshellarg($php),
            escapeshellarg($script),
            self::SESSION_ID,
            escapeshellarg($this->testProjectDir),
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
            $this->fail('Failed to migrate test database for /repair E2E: '.implode("\n", $output));
        }

        TuiE2eDatabaseEnv::ensureIsolatedMessengerTransportSchema(
            TuiE2eDatabaseEnv::isolatedSqliteAbsolutePath($this->testProjectDir, $this->transportDbPath),
        );
    }

    private function seedStaleCancellationSession(): void
    {
        $sessionDir = $this->testProjectDir.'/.hatfield/sessions/'.self::SESSION_ID;
        TestDirectoryIsolation::ensureDirectory($sessionDir);

        $lines = [
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":2,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"follow_up","payload":{"text":"run subagent"}},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":3,"turn_no":33,"type":"turn_advanced","payload":{"turn_no":33,"step_id":"follow_up-abc"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":4,"turn_no":33,"type":"llm_step_completed","payload":{"step_id":"follow_up-xyz","assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_00_abc","type":"function","function":{"name":"subagent","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:03+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":5,"turn_no":33,"type":"tool_execution_start","payload":{"tool_call_id":"call_00_abc","tool_name":"subagent","order_index":0,"mode":"async","step_id":"follow_up-xyz"},"ts":"2026-07-09T01:00:04+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":6,"turn_no":33,"type":"tool_call_result_received","payload":{"tool_call_id":"call_00_abc","order_index":0,"is_error":false},"ts":"2026-07-09T01:00:05+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":7,"turn_no":33,"type":"tool_execution_end","payload":{"tool_call_id":"call_00_abc","order_index":0,"is_error":false,"result":"done"},"ts":"2026-07-09T01:00:06+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":8,"turn_no":33,"type":"message_start","payload":{"message_role":"tool","tool_call_id":"call_00_abc"},"ts":"2026-07-09T01:00:07+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":9,"turn_no":33,"type":"message_end","payload":{"message_role":"tool","tool_call_id":"call_00_abc","message":{"role":"tool","content":"done","tool_call_id":"call_00_abc"}},"ts":"2026-07-09T01:00:08+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":10,"turn_no":33,"type":"tool_batch_committed","payload":{"count":1,"turn_no":33,"step_id":"follow_up-xyz"},"ts":"2026-07-09T01:00:09+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":11,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:10+00:00"}',
            '{"schema_version":"1.0","run_id":"'.self::SESSION_ID.'","seq":12,"turn_no":33,"type":"agent_command_rejected","payload":{"reason":"Command \"follow_up\" rejected because cancellation is in progress."},"ts":"2026-07-09T01:00:11+00:00"}',
        ];
        file_put_contents($sessionDir.'/events.jsonl', implode("\n", $lines)."\n");

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
        $state = new RunState(
            runId: self::SESSION_ID,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: 12,
            pendingToolCalls: ['call_00_abc' => true],
            activeStepId: 'follow_up-xyz',
        );
        $json = json_encode($serializer->normalize($state), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        file_put_contents($sessionDir.'/state.json', $json);

        // Doctrine opens kernel.project_dir/var/test/{env}; env is relative to var/test (see TuiE2eDatabaseEnv).
        $pdo = new \PDO('sqlite:'.$this->appDbAbsolutePath);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO hatfield_session (id, cwd, prompt, name, created_at, updated_at) VALUES (:id, :cwd, :prompt, :name, :created_at, :updated_at)');
        $stmt->execute([
            'id' => (int) self::SESSION_ID,
            'cwd' => $this->testProjectDir,
            'prompt' => 'stale cancel repair e2e',
            'name' => 'repair-e2e',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertAgentEndCancelledAppended(): void
    {
        $path = $this->testProjectDir.'/.hatfield/sessions/'.self::SESSION_ID.'/events.jsonl';
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
