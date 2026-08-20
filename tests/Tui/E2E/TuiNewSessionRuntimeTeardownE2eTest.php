<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Replay-backed TmuxHarness proof: /new stops the previous controller/consumers
 * while an in-flight tool run is still active, keeps the draft free of old
 * progress/result text, and still allows a fresh session to start afterward.
 *
 * @group tui-e2e-replay
 */
#[Group('tui-e2e-replay')]
final class TuiNewSessionRuntimeTeardownE2eTest extends TestCase
{
    use BashBackgroundE2eTestSupport;

    private const string NEW_SESSION_PROMPT = 'Say exactly NEW_SESSION_AFTER_NEW_OK';
    private const string NEW_SESSION_SENTINEL = 'NEW_SESSION_AFTER_NEW_OK';

    protected function setUp(): void
    {
        $this->setUpBashBackgroundE2e('tui-new-teardown', 'tui-e2e-new-teardown');
        $this->tmux->setSnapshotDir($this->testProjectDir);
        $this->raiseBackgroundPromptThresholdForInFlightNew();
    }

    protected function tearDown(): void
    {
        $this->tearDownBashBackgroundE2e();
    }

    public function testNewDuringInFlightBashStopsOldRuntimeAndKeepsDraftClean(): void
    {
        $pane = $this->tmux->startDetached(
            command: $this->agentCommandWithFixtures(
                'tui-tool-call-bash-sleep8.json',
                'tui-new-after-inflight-text.json',
            ),
            prefix: 'tui-new-teardown',
            width: 120,
            height: 60,
            cwd: $this->testProjectDir,
        );

        try {
            $this->prepareEditorForUserPrompt($this->tmux, $pane);

            $this->tmux->sendLiteral($pane, 'Run sleep 4');
            $this->tmux->sendKey($pane, 'Enter');

            $oldSessionId = null;
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap) use (&$oldSessionId): bool {
                    if (!preg_match('/session\s+(\d+)/', $cap, $matches)) {
                        return false;
                    }
                    $oldSessionId = $matches[1];

                    return str_contains($cap, 'Running')
                        || str_contains($cap, 'sleep 4')
                        || str_contains($cap, '◐ Work');
                },
                timeout: 20.0,
                message: 'In-flight bash run and session id must appear before /new',
                history: 2000,
            );
            $this->assertNotEmpty($oldSessionId, 'Old session id must be captured before /new');
            $this->assertTrue(
                $this->waitForLogCount('controller.session_owner_lock_acquired', 1, 8.0),
                'Old session controller must acquire the session-owner lock before /new',
            );

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, '/new');
            $this->tmux->waitForCallback(
                $pane,
                static function (string $cap): bool {
                    return str_contains($cap, 'Completions')
                        && str_contains($cap, '/new');
                },
                timeout: TmuxHarness::TUI_GATE_CALLBACK_TIMEOUT_PARALLEL,
                message: 'Typing "/new" must open the Completions overlay showing /new',
                history: 2000,
            );
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCaptureContains(
                $pane,
                'Welcome to Hatfield',
                TmuxHarness::TUI_STARTUP_LOGO_TIMEOUT_PARALLEL,
            );
            $draftPane = $this->tmux->waitForCallback(
                $pane,
                static fn (string $plain): bool => (str_contains($plain, '● idle') || str_contains($plain, '◐ Work'))
                    && str_contains($plain, '◆')
                    && str_contains($plain, 'Welcome to Hatfield'),
                timeout: 8.0,
                message: 'Fresh /new draft must reach idle-ready welcome state',
                history: 0,
            );

            $this->assertStringContainsString('Welcome to Hatfield', $draftPane);
            $this->assertStringNotContainsString('session '.$oldSessionId, $draftPane);
            $this->assertStringNotContainsString('sleep 4', $draftPane);
            $this->assertStringNotContainsString('Running', $draftPane);
            $this->assertStringNotContainsString('❯ Run sleep 4', $draftPane);
            $this->assertStringNotContainsString(self::NEW_SESSION_SENTINEL, $draftPane);

            $this->assertTrue(
                $this->waitForLogCount('Controller shutting down gracefully', 1, 12.0),
                'Old controller must shut down synchronously when switching to the /new draft',
            );
            $this->assertSame(
                1,
                mb_substr_count($this->readAgentLog(), 'controller.session_owner_lock_acquired'),
                'Draft after /new must not spawn a replacement controller',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'new-during-inflight-draft');

            $this->tmux->sendKey($pane, 'C-u');
            $this->tmux->sendLiteral($pane, self::NEW_SESSION_PROMPT);
            $this->tmux->sendKey($pane, 'Enter');

            $this->tmux->waitForCallback(
                $pane,
                static fn (string $cap): bool => str_contains($cap, self::NEW_SESSION_SENTINEL)
                    && 1 === preg_match('/session\s+(\d+)/', $cap),
                timeout: TmuxHarness::TUI_ASSISTANT_BLOCK_TIMEOUT_PARALLEL,
                message: 'Fresh session after /new must start and show the new assistant reply',
                history: 2000,
            );

            $afterCapture = $this->tmux->capturePlainWithHistory($pane, 2500);
            $this->assertStringContainsString(self::NEW_SESSION_SENTINEL, $afterCapture);
            $this->assertStringNotContainsString('session '.$oldSessionId, $afterCapture);
            $this->assertStringNotContainsString('sleep 4', $afterCapture);
            $this->assertTrue(
                $this->waitForLogCount('controller.session_owner_lock_acquired', 2, 10.0),
                'First prompt after /new must start a fresh session-scoped controller',
            );

            $this->tmux->saveAnsiSnapshot($pane, 'new-during-inflight-fresh-session');
            $this->tmux->sendKey($pane, 'C-d');
            $this->assertNoLeakedWorkersForThisTestWithRetry();
        } catch (\Throwable $e) {
            $this->tmux->saveAnsiSnapshot($pane, 'new-during-inflight-FAILURE');
            try {
                $this->tmux->sendKey($pane, 'C-d');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }

    private function readAgentLog(): string
    {
        $content = '';
        foreach (glob($this->testProjectDir.'/.hatfield/logs/agent*.log') ?: [] as $path) {
            $chunk = @file_get_contents($path);
            if (false !== $chunk) {
                $content .= $chunk;
            }
        }

        return $content;
    }

    /**
     * Shared bash-background project settings use a 1s prompt threshold. Raise it
     * locally so the in-flight sleep cannot open a ToolQuestion overlay that would
     * steal typed `/new` as an answer.
     */
    private function raiseBackgroundPromptThresholdForInFlightNew(): void
    {
        $path = $this->testProjectDir.'/.hatfield/settings.yaml';
        $settings = Yaml::parseFile($path);
        if (!\is_array($settings)) {
            $this->fail('Isolated bash-background settings.yaml must parse to an array');
        }

        $settings['tools']['bash']['background_prompt_threshold_seconds'] = 60;
        TuiE2eDatabaseEnv::writeReplaySettings($this->testProjectDir, $settings);
    }

    private function waitForLogCount(string $needle, int $expected, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($expected <= mb_substr_count($this->readAgentLog(), $needle)) {
                return true;
            }
            usleep(100_000);
        }

        return $expected <= mb_substr_count($this->readAgentLog(), $needle);
    }
}
