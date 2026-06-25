<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * TUI E2E proof: queued steer during active tool execution renders pending ⏳
 * in the queue widget above the editor, then pops from the widget and appends
 * the canonical ❯ user message to transcript history when applied (issue #206).
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiQueuedSteerE2eTest extends TestCase
{
    private const string STEER_MARKER = 'STEER_QUEUED_MARKER remember to use snake_case';

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
    }

    public function testQueuedSteerShowsPendingThenReconcilesToUserMessage(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommand(),
            prefix: 'tui-queued-steer',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            // 20s under parallel castor check (see TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL).
            $this->tmux->waitForCaptureContains($pane, '█', TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL);
            $this->tmux->waitForTuiReadyAfterLogo($pane);

            $this->tmux->sendKey($pane, 'C-u');
            usleep(100_000);
            $this->tmux->sendLiteral($pane, 'Run sleep 3');
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '●'),
                timeout: 15.0,
                message: 'Tool-call block (●) did not appear — run must be active before steer',
                history: 2000,
            );

            $this->tmux->sendLiteral($pane, self::STEER_MARKER);
            $this->tmux->sendKey($pane, 'Enter');

            $marker = self::STEER_MARKER;
            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, '⏳')
                    && str_contains($cap, $marker),
                timeout: 10.0,
                message: 'Pending queued steer (⏳ in queue widget above editor) with marker text did not appear',
                history: 2000,
            );
            $this->saveAnsiSnapshot($pane, 'queued-steer-pending');

            $this->tmux->waitForCallback(
                $pane,
                // '❯ ' + marker is unambiguous: pending feedback is in the queue widget ('⏳ ' + marker),
                // not in transcript history. The initial prompt is '❯ Run sleep 3' (no marker).
                // True only once apply appends the canonical user-message block to history.
                static fn (string $cap): bool => str_contains($cap, '❯ '.$marker),
                timeout: 30.0,
                message: 'Canonical user message (❯ + steer text) did not appear after apply',
                history: 3000,
            );

            $finalCapture = $this->tmux->captureAnsi($pane);
            $this->assertSame(
                1,
                substr_count($finalCapture, self::STEER_MARKER),
                'Steer marker must appear exactly once in the final transcript (no duplicate user blocks)',
            );
            // The renderer emits "  ⏳ " + text (prefix has a trailing space), so assert the
            // spaced form — the unspaced "⏳"+marker substring could never appear (vacuous).
            $this->assertStringNotContainsString(
                '⏳ '.self::STEER_MARKER,
                $finalCapture,
                'Pending ⏳ queue-widget entry for the steer must be gone after apply (message is in history as ❯)',
            );
            $this->saveAnsiSnapshot($pane, 'queued-steer-reconciled');

            $this->assertSteerQueueApplyLifecycleInEventsJsonl();

            $this->tmux->sendKey($pane, 'C-d');
        } catch (\Throwable $e) {
            $this->saveAnsiSnapshot($pane, 'queued-steer-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function assertSteerQueueApplyLifecycleInEventsJsonl(): void
    {
        $eventLog = $this->testProjectDir.'/.hatfield/sessions/1/events.jsonl';
        if (!is_file($eventLog)) {
            $this->fail('events.jsonl not found at '.$eventLog);
        }

        $lines = file($eventLog, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $queuedSeq = null;
        $appliedSeq = null;

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (!\is_array($decoded)) {
                continue;
            }
            $type = (string) ($decoded['type'] ?? '');
            $payload = $decoded['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            $kind = (string) ($payload['kind'] ?? '');
            $seq = (int) ($decoded['seq'] ?? 0);

            if ('agent_command_queued' === $type && 'steer' === $kind) {
                $queuedSeq = $seq;
            }
            if ('agent_command_applied' === $type && 'steer' === $kind) {
                $appliedSeq = $seq;
            }
        }

        $this->assertNotNull($queuedSeq, 'events.jsonl must contain agent_command_queued with kind=steer');
        $this->assertNotNull($appliedSeq, 'events.jsonl must contain agent_command_applied with kind=steer');
        $this->assertGreaterThan(
            $queuedSeq,
            $appliedSeq,
            'agent_command_applied (steer) must follow agent_command_queued (steer)',
        );
    }

    private function agentCommand(): string
    {
        $fixturePath = __DIR__.'/fixtures/tui-queued-steer-bash-sleep.json';

        $projectDir = ProjectDir::get();
        $fixtureEnv = is_file($fixturePath)
            ? 'HATFIELD_LLM_REPLAY_FIXTURE_PATH='.escapeshellarg($fixturePath).' '
            : '';

        $dbPath = 'app_test-tui-queued-steer-'.bin2hex(random_bytes(4)).'.sqlite';

        return \sprintf(
            'APP_ENV=test HATFIELD_TEST_DATABASE_PATH=%s HOME=%s %s %s %s agent '
            .'--model=llama_cpp_test/test '
            .'2>&1',
            escapeshellarg($dbPath),
            escapeshellarg($this->testProjectDir.'/home'),
            $fixtureEnv,
            escapeshellarg(\PHP_BINARY),
            escapeshellarg($projectDir.'/bin/console'),
        );
    }

    private function createIsolatedProjectDir(): string
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('tui-e2e-queued-steer');
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
                                    'off' => '0', 'minimal' => '0', 'low' => '0',
                                    'medium' => '0', 'high' => '0', 'xhigh' => '0',
                                ],
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
                        'allow_command_patterns' => ['^ls\b', '^printf\b', '^echo\b', '^sleep\b'],
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

    private function saveAnsiSnapshot(TmuxPane $pane, string $tag): void
    {
        $ansi = $this->tmux->captureAnsi($pane);
        $path = \sprintf('%s/%s-%s.ansi', $this->snapshotDir, $tag, date('Ymd-His'));
        file_put_contents($path, $ansi);
    }
}
